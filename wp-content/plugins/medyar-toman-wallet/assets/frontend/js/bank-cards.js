/**
 * Medyar bank vault — plastic preview + inline composer (no modal).
 * Keeps AJAX + selectors used by Medyar_Bank_Cards.
 */
(function ($) {
    'use strict';

    function parseAjaxResponse(raw) {
        if (raw && typeof raw === 'object') {
            return raw;
        }
        try {
            return JSON.parse(String(raw || '').replace(/^\uFEFF/, '').trim());
        } catch (e) {
            return null;
        }
    }

    function postBankCards(data) {
        return $.ajax({
            url: medyarBankCards.ajaxUrl,
            type: 'POST',
            dataType: 'text',
            data: data,
        }).then(function (raw) {
            const parsed = parseAjaxResponse(raw);
            if (!parsed) {
                return $.Deferred().reject({ status: 500, responseText: raw }).promise();
            }
            return parsed;
        });
    }

    function notify(type, message) {
        if (!message) {
            return;
        }
        if (type === 'success' && typeof window.showSuccessToast === 'function') {
            window.showSuccessToast(message);
            return;
        }
        if (type === 'error' && typeof window.showErrorToast === 'function') {
            window.showErrorToast(message);
            return;
        }
        let $toast = $('#mtwBankCardsToast');
        if (!$toast.length) {
            $toast = $('<div>', { id: 'mtwBankCardsToast', class: 'mtw-toast', role: 'status', 'aria-live': 'polite' }).appendTo(document.body);
        }
        $toast
            .removeClass('is-success is-error is-visible')
            .addClass(type === 'success' ? 'is-success' : 'is-error')
            .text(String(message))
            .addClass('is-visible');
        clearTimeout(window.__mtwToastTimer);
        window.__mtwToastTimer = setTimeout(function () {
            $toast.removeClass('is-visible');
        }, 2800);
    }

    let isEventsInitialized = false;

    function getMaxBankCards() {
        if (typeof medyarBankCards !== 'undefined' && medyarBankCards.max_cards) {
            const n = parseInt(medyarBankCards.max_cards, 10);
            if (n > 0) {
                return n;
            }
        }
        return 3;
    }

    function formatCardNumber(cardNumber) {
        if (!cardNumber) return '';
        const cleaned = String(cardNumber).replace(/\D/g, '');
        if (cleaned.length === 16) {
            return cleaned.match(/.{1,4}/g).join(' ');
        }
        return cardNumber;
    }

    function persianToEnglish(str) {
        if (typeof str !== 'string') return '';
        const map = {
            '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
            '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
            '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4',
            '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
        };
        return str.replace(/[۰-۹٠-٩]/g, function (d) {
            return map[d] || d;
        });
    }

    function getCardLast4(cardNumber) {
        if (typeof cardNumber !== 'string' && typeof cardNumber !== 'number') return '';
        return persianToEnglish(String(cardNumber)).replace(/\D/g, '').slice(-4);
    }

    function getCardIdFromEl($card) {
        if (!$card || !$card.length) return '';
        const fromAttr = persianToEnglish(String($card.attr('data-card-id') || '')).replace(/\D/g, '');
        if (fromAttr.length === 16) {
            return fromAttr;
        }
        const fromNumber = persianToEnglish($card.find('.bank-card-number-ws, .bank-card-number').first().text() || '')
            .replace(/\D/g, '');
        return fromNumber.length === 16 ? fromNumber : '';
    }

    const STORAGE_KEY_CARD_ID = 'mtw_default_bank_card_id';
    const STORAGE_KEY_LAST4_LEGACY = 'selected_bank_card_last4';

    function getSelectedCardFromStorage() {
        try {
            const id = localStorage.getItem(STORAGE_KEY_CARD_ID);
            if (id && /^\d{16}$/.test(id)) {
                return { id: id, last4: id.slice(-4) };
            }
            const last4 = localStorage.getItem(STORAGE_KEY_LAST4_LEGACY);
            if (last4 && /^\d{4}$/.test(last4)) {
                return { id: null, last4: last4 };
            }
            return null;
        } catch (e) {
            return null;
        }
    }

    function saveSelectedCardToStorage(cardIdOrLast4) {
        try {
            const raw = persianToEnglish(String(cardIdOrLast4 || '')).replace(/\D/g, '');
            if (raw.length === 16) {
                localStorage.setItem(STORAGE_KEY_CARD_ID, raw);
                localStorage.setItem(STORAGE_KEY_LAST4_LEGACY, raw.slice(-4));
                return;
            }
            if (raw.length === 4) {
                localStorage.setItem(STORAGE_KEY_LAST4_LEGACY, raw);
                localStorage.removeItem(STORAGE_KEY_CARD_ID);
                return;
            }
            localStorage.removeItem(STORAGE_KEY_CARD_ID);
            localStorage.removeItem(STORAGE_KEY_LAST4_LEGACY);
        } catch (e) {
            // ignore
        }
    }

    function clearCardSelectionUi($list) {
        const $cards = $list.children('.bank-card-item-ws, .bank-card-item');
        $cards.removeClass('selected').attr('aria-checked', 'false').attr('tabindex', '-1');
        $cards.find('.bank-card-selected-badge-ws, .bank-card-selected-badge').remove();
    }

    function markCardAsDefault($card) {
        if (!$card || !$card.length) {
            return;
        }
        $card.addClass('selected').attr('aria-checked', 'true').attr('tabindex', '0');
        const $top = $card.find('.mtw-plastic__top').first();
        if ($top.length && $top.find('.bank-card-selected-badge-ws, .bank-card-selected-badge').length === 0) {
            $top.append($('<span>', {
                class: 'bank-card-selected-badge bank-card-selected-badge-ws',
                text: 'پیش‌فرض',
            }));
        }
    }

    /** Persist + paint default selection on a card element. */
    function selectDefaultBankCard($card, opts) {
        opts = opts || {};
        const $list = $('#bankCardsList');
        if (!$card || !$card.length || !$list.length) {
            return false;
        }
        const cardId = getCardIdFromEl($card);
        if (!cardId) {
            return false;
        }
        clearCardSelectionUi($list);
        markCardAsDefault($card);
        if (opts.persist !== false) {
            saveSelectedCardToStorage(cardId);
        }
        return true;
    }

    function findCardByStoredPreference($list, stored) {
        if (!$list || !$list.length || !stored) {
            return $();
        }
        if (stored.id) {
            const $byId = $list.children('.bank-card-item-ws, .bank-card-item').filter(function () {
                return getCardIdFromEl($(this)) === stored.id;
            });
            if ($byId.length) {
                return $byId.first();
            }
        }
        if (stored.last4) {
            const $byLast4 = $list.children('.bank-card-item-ws, .bank-card-item').filter(function () {
                return getCardLast4(getCardIdFromEl($(this))) === stored.last4;
            });
            if ($byLast4.length) {
                return $byLast4.first();
            }
        }
        return $();
    }

    /** Apply default from localStorage (or first card) after DOM is ready. */
    function applyDefaultBankCardSelection($list) {
        $list = $list && $list.length ? $list : $('#bankCardsList');
        const $cards = $list.children('.bank-card-item-ws, .bank-card-item');
        if ($cards.length === 0) {
            saveSelectedCardToStorage(null);
            return;
        }
        if ($cards.length === 1) {
            selectDefaultBankCard($cards.first());
            return;
        }
        const stored = getSelectedCardFromStorage();
        const $preferred = findCardByStoredPreference($list, stored);
        if ($preferred.length) {
            selectDefaultBankCard($preferred);
            return;
        }
        // No valid stored preference — keep existing .selected if any, else first card.
        const $already = $cards.filter('.selected').first();
        selectDefaultBankCard($already.length ? $already : $cards.first());
    }

    function updateCardCountMeter() {
        const n = $('#bankCardsList .bank-card-item-ws').length;
        $('#mtwCardCountLabel').text(String(n));
    }

    function normalizeIban(raw) {
        let iban = persianToEnglish(String(raw || '')).toUpperCase().replace(/[^A-Z0-9]/g, '');
        if (iban.indexOf('IR') === 0) {
            iban = iban.slice(2);
        }
        const digits = iban.replace(/\D/g, '').substring(0, 24);
        return digits ? ('IR' + digits) : '';
    }

    function formatIbanDigitsDisplay(raw) {
        const digits = normalizeIban(raw).replace(/^IR/, '');
        if (!digits) {
            return '';
        }
        return digits.replace(/(.{4})/g, '$1 ').trim();
    }

    /** Display as IR + 24 digits in groups of 4: IR 7001 7000 … */
    function formatIbanDisplay(iban) {
        const clean = normalizeIban(iban);
        if (!clean) {
            return '';
        }
        const digits = clean.slice(2);
        return ('IR ' + digits.replace(/(.{4})/g, '$1 ').trim()).trim();
    }

    function isValidIban(iban) {
        const clean = normalizeIban(iban);
        const digits = clean.replace(/^IR/, '');
        if (digits.length !== 24) {
            return false;
        }
        if (!/^IR\d{24}$/.test(clean)) {
            return false;
        }
        const rearranged = clean.slice(4) + clean.slice(0, 4);
        let numeric = '';
        for (let i = 0; i < rearranged.length; i++) {
            const ch = rearranged.charAt(i);
            if (ch >= 'A' && ch <= 'Z') {
                numeric += String(ch.charCodeAt(0) - 55);
            } else {
                numeric += ch;
            }
        }
        let checksum = 0;
        for (let i = 0; i < numeric.length; i++) {
            checksum = (checksum * 10 + (numeric.charCodeAt(i) - 48)) % 97;
        }
        return checksum === 1;
    }

    function clearComposerFields() {
        $('#newCardNumber').val('');
        $('#newCardIban').val('');
        $('#newCardNumberError').text('');
        $('#newCardIbanError').text('');
        updateLivePreview('');
        updateLiveIban('');
    }

    function setComposerOpen(open) {
        const $composer = $('#mtwAddCardComposer');
        const $body = $('#mtwComposerBody');
        const $btn = $('#addCardBtn');
        if (!$composer.length) {
            return;
        }
        $composer.toggleClass('is-open', !!open);
        $btn.attr('aria-expanded', open ? 'true' : 'false');
        if (open) {
            $body.prop('hidden', false);
            setTimeout(function () {
                $('#newCardNumber').trigger('focus');
            }, 40);
        } else {
            $body.prop('hidden', true);
            clearComposerFields();
        }
    }

    function updateLiveIban(raw) {
        const clean = normalizeIban(raw);
        const digits = clean.length > 2 ? clean.slice(2) : '';
        const padded = (digits + '••••••••••••••••••••••••').slice(0, 24);
        const display = 'IR ' + padded.replace(/(.{4})/g, '$1 ').trim();
        $('#mtwLiveCardIban').text(display);
        $('#mtwLiveCardPreview').toggleClass('has-iban', digits.length >= 4);
    }

    function fallbackBankIconHtml() {
        return '<span class="drplus-simple-icon-wrap icon-has-bg mtw-section-icon mtw-card-fallback-icon">' +
            '<i class="drplus-icon-bitcoin-card drplus-simple-icon" aria-hidden="true"></i></span>';
    }

    function isDefaultBankSlug(en) {
        return !en || en === 'bank-logo-default-ws' || en === 'default';
    }

    function guessBank(digits) {
        const bins = (typeof medyarBankCards !== 'undefined' && medyarBankCards.banks)
            ? medyarBankCards.banks
            : {};
        const bin6 = String(digits || '').slice(0, 6);
        if (bin6.length < 6) {
            return { fa: 'کارت بانکی', en: 'bank-logo-default-ws', known: false };
        }
        if (bins[bin6] && !isDefaultBankSlug(bins[bin6].en)) {
            return {
                fa: bins[bin6].fa || 'کارت بانکی',
                en: bins[bin6].en,
                known: true,
            };
        }
        if (bins[bin6]) {
            return {
                fa: bins[bin6].fa || 'کارت بانکی',
                en: bins[bin6].en || 'bank-logo-default-ws',
                known: false,
            };
        }
        // BIN ناشناخته: بدون خطا — برچسب خنثی + آیکن پیش‌فرض
        return { fa: 'کارت بانکی', en: 'bank-logo-default-ws', known: false };
    }

    function setLogoWrapContent($wrap, bank) {
        if (!$wrap || !$wrap.length) {
            return;
        }
        if (bank.known && !isDefaultBankSlug(bank.en)) {
            $wrap.html($('<div>', {
                class: 'bank-logo-img ' + bank.en,
                id: $wrap.is('#mtwLiveBankLogo') ? 'mtwLiveBankLogoImg' : undefined,
                'aria-label': bank.fa,
            }));
            return;
        }
        $wrap.html(fallbackBankIconHtml());
        if ($wrap.is('#mtwLiveBankLogo')) {
            $wrap.find('.mtw-card-fallback-icon').attr('id', 'mtwLiveBankLogoImg');
        }
    }

    function updateLivePreview(raw) {
        const digits = persianToEnglish(String(raw || '')).replace(/\D/g, '').substring(0, 16);
        let display = '•••• •••• •••• ••••';
        if (digits.length) {
            const padded = (digits + '••••••••••••••••').slice(0, 16);
            display = padded.match(/.{1,4}/g).join(' ');
        }
        const bank = guessBank(digits);
        $('#mtwLiveCardNumber').text(display);
        $('#mtwLiveBankName').text(bank.fa);
        setLogoWrapContent($('#mtwLiveBankLogo'), bank);
        $('#mtwLiveCardPreview')
            .toggleClass('is-filled', digits.length >= 6 && bank.known)
            .toggleClass('is-unknown', digits.length >= 6 && !bank.known);
    }

    window.refreshBankCardsData = function () {
        fetchBankCardsData();
    };

    function fetchBankCardsData() {
        if (typeof medyarBankCards !== 'undefined' && Array.isArray(medyarBankCards.initialCards)) {
            populateBankCardsDOM({ cards: medyarBankCards.initialCards });
            return;
        }
        const $list = $('#bankCardsList');
        if ($list.children('.bank-card-item-ws').length > 0) {
            applyDefaultBankCardSelection($list);
        }
        updateCardCountMeter();
    }

    function populateBankCardsDOM(data) {
        if (window.medyar_PANEL_SKELETON_DEBUG === true) {
            return;
        }
        if (Array.isArray(data.cards)) {
            renderBankCards(data.cards);
        }
    }

    function ensureSingleBankCardSelectedInList($list) {
        applyDefaultBankCardSelection($list);
    }

    function renderBankCards(cards) {
        const $list = $('#bankCardsList');
        $list.empty();

        if (!Array.isArray(cards) || cards.length === 0) {
            $('#noCardsMessage').show();
            updateCardCountMeter();
            saveSelectedCardToStorage(null);
            setComposerOpen(true);
            return;
        }

        $('#noCardsMessage').hide();
        const stored = getSelectedCardFromStorage();
        const forceSelectOnly = cards.length === 1;
        let matchedStored = false;

        cards.forEach(function (card) {
            const cardNumber = card.card_number_formatted || card.cardNumber || card.number || '';
            const bankNameFa = card.bank_name_fa || card.bankName || card.name || 'کارت بانکی';
            const bankNameEn = card.bank_name_en || card.bankNameEn || 'bank-logo-default-ws';
            const iban = card.iban || '';
            const owner = card.owner || '';
            const cardId = persianToEnglish(String(cardNumber)).replace(/\D/g, '');
            const cardLast4 = getCardLast4(cardNumber);
            let isSelected = false;
            if (forceSelectOnly) {
                isSelected = true;
            } else if (stored) {
                if (stored.id && stored.id === cardId) {
                    isSelected = true;
                    matchedStored = true;
                } else if (!stored.id && stored.last4 && stored.last4 === cardLast4 && !matchedStored) {
                    isSelected = true;
                    matchedStored = true;
                }
            }
            $list.append(createBankCardElement({
                cardNumber: cardNumber,
                bankNameFa: bankNameFa,
                bankNameEn: bankNameEn,
                iban: iban,
                owner: owner,
                isSelected: isSelected,
            }));
        });

        applyDefaultBankCardSelection($list);
        updateCardCountMeter();
        if (cards.length >= getMaxBankCards()) {
            setComposerOpen(false);
        }
    }

    function createBankCardElement(opts) {
        const cardNumber = opts.cardNumber || '';
        const bankNameFa = opts.bankNameFa || 'کارت بانکی';
        const bankNameEn = opts.bankNameEn || 'default';
        const iban = opts.iban || '';
        const owner = opts.owner || '';
        const isSelected = !!opts.isSelected;
        const cardId = String(cardNumber).replace(/\D/g, '');
        const formattedCardNumber = formatCardNumber(cardNumber);

        const $cardItem = $('<div>', {
            class: 'bank-card-item bank-card-item-ws mtw-plastic' + (isSelected ? ' selected' : ''),
            role: 'radio',
            'aria-checked': isSelected,
            tabindex: isSelected ? 0 : -1,
            'data-card-id': cardId,
        });

        const $icon = $('<div>', { class: 'bank-card-icon bank-card-icon-ws' });
        if (!isDefaultBankSlug(bankNameEn)) {
            $icon.append($('<div>', { class: 'bank-logo-img ' + bankNameEn, 'aria-label': bankNameFa }));
        } else {
            $icon.append($(fallbackBankIconHtml()));
        }

        const $top = $('<div>', { class: 'mtw-plastic__top' }).append(
            $('<div>', { class: 'bank-card-name bank-card-name-ws', text: bankNameFa })
        );
        if (isSelected) {
            $top.append($('<span>', { class: 'bank-card-selected-badge bank-card-selected-badge-ws', text: 'پیش‌فرض' }));
        }

        const $face = $('<div>', { class: 'bank-card-info bank-card-info-ws mtw-plastic__face' }).append(
            $icon,
            $top,
            $('<div>', { class: 'mtw-plastic__chip', 'aria-hidden': 'true' }),
            $('<div>', { class: 'bank-card-number bank-card-number-ws', text: formattedCardNumber }),
            $('<div>', {
                class: 'bank-card-iban bank-card-iban-ws' + (iban ? '' : ' is-empty'),
                dir: 'ltr',
                text: iban ? (formatIbanDisplay(iban) || iban) : 'شبا ثبت نشده',
            }),
            $('<input>', { type: 'hidden', class: 'card-iban', value: iban }),
            $('<input>', { type: 'hidden', class: 'card-owner', value: owner })
        );

        const $removeBtn = $('<button>', {
            class: 'bank-card-remove-btn bank-card-remove-btn-ws',
            type: 'button',
            'data-card-id': cardId,
            'aria-label': 'حذف کارت ' + bankNameFa,
            text: 'حذف',
        });

        const $confirm = $('<div>', { class: 'mtw-plastic__confirm', hidden: true }).append(
            $('<span>', { text: 'این کارت حذف شود؟' }),
            $('<button>', { type: 'button', class: 'mtw-plastic__confirm-yes', 'data-card-id': cardId, text: 'بله' }),
            $('<button>', { type: 'button', class: 'mtw-plastic__confirm-no', text: 'خیر' })
        );

        $cardItem.append(
            $face,
            $('<div>', { class: 'bank-card-actions bank-card-actions-ws mtw-plastic__actions' }).append($removeBtn),
            $confirm
        );

        return $cardItem;
    }

    function clearRemoveConfirms() {
        $('.mtw-plastic').removeClass('is-confirming');
        $('.mtw-plastic__confirm').prop('hidden', true);
    }

    function removeCard(cardId) {
        if (typeof medyarBankCards === 'undefined') {
            notify('error', 'خطا در اتصال به سرور. لطفا صفحه را رفرش کنید.');
            return;
        }

        const $allMatches = $(
            '#bankCardsList .bank-card-item-ws[data-card-id="' + cardId + '"]'
        );
        if ($allMatches.length === 0) {
            notify('error', 'کارت یافت نشد.');
            return;
        }

        const $cardForNumber = $allMatches.first();
        const cardNumberText = $cardForNumber.find('.bank-card-number-ws').first().text();
        const cardNumber = cardNumberText.replace(/\D/g, '');

        if (!cardNumber || cardNumber.length !== 16) {
            notify('error', 'شماره کارت نامعتبر است.');
            return;
        }

        if ($cardForNumber.hasClass('selected')) {
            saveSelectedCardToStorage(null);
        }

        $allMatches.addClass('is-busy');

        postBankCards({
            action: medyarBankCards.removeAction,
            nonce: medyarBankCards.nonce,
            card_number: cardNumber,
        })
            .done(function (response) {
                if (response.success) {
                    if (response.data && response.data.cards) {
                        medyarBankCards.initialCards = response.data.cards;
                    }
                    clearRemoveConfirms();

                    if (window.MyAccountCache) {
                        if (typeof window.MyAccountCache.markBankCardsDirty === 'function') {
                            window.MyAccountCache.markBankCardsDirty();
                        }
                        window.MyAccountCache.invalidate('bank-cards');
                        window.MyAccountCache.invalidate('wallet');
                        window.MyAccountCache.invalidateSession('wallet');
                        window.MyAccountCache.invalidateSession('bank-cards');
                    }

                    $allMatches.css({
                        opacity: '0',
                        transform: 'translateY(10px) scale(0.985)',
                        transition: 'opacity 0.36s cubic-bezier(0.22, 1, 0.36, 1), transform 0.36s cubic-bezier(0.22, 1, 0.36, 1)',
                    });

                    setTimeout(function () {
                        $allMatches.remove();
                        updateCardCountMeter();
                        const $remaining = $('#bankCardsList').children('.bank-card-item-ws');
                        if ($remaining.length === 0) {
                            $('#noCardsMessage').show();
                            saveSelectedCardToStorage(null);
                            setComposerOpen(true);
                        } else {
                            applyDefaultBankCardSelection($('#bankCardsList'));
                        }
                    }, 360);

                    notify('success', (response.data && response.data.message) || 'کارت با موفقیت حذف شد');
                } else {
                    const msg = (response.data && response.data.message) ? response.data.message : 'خطا در حذف کارت بانکی';
                    notify('error', msg);
                }
            })
            .fail(function () {
                notify('error', 'خطا در ارتباط با سرور. لطفا دوباره تلاش کنید.');
            })
            .always(function () {
                $allMatches.removeClass('is-busy');
            });
    }

    window.initBankCardsSection = function () {
        if ($('#addCardBtn').length === 0 || $('#newCardNumber').length === 0) {
            setTimeout(window.initBankCardsSection, 100);
            return;
        }

        fetchBankCardsData();

        if (isEventsInitialized) {
            return;
        }
        isEventsInitialized = true;

        $(document).off('input.mtwVault', '#newCardNumber').on('input.mtwVault', '#newCardNumber', function () {
            let value = persianToEnglish(this.value).replace(/\D/g, '').substring(0, 16);
            this.value = value.length > 0 ? (value.match(/.{1,4}/g) || [value]).join('-') : '';
            updateLivePreview(this.value);
            $('#newCardNumberError').text('');
        });

        $(document).off('input.mtwVault', '#newCardIban').on('input.mtwVault', '#newCardIban', function () {
            const formatted = formatIbanDigitsDisplay(this.value);
            this.value = formatted;
            updateLiveIban('IR' + formatted);
            $('#newCardIbanError').text('');
        });

        $(document).off('click.mtwVault', '#addCardBtn, #addCardFabBtn').on('click.mtwVault', '#addCardBtn, #addCardFabBtn', function () {
            const maxCards = getMaxBankCards();
            const cardCount = $('#bankCardsList .bank-card-item-ws, #bankCardsList .bank-card-item').length;
            const willOpen = !$('#mtwAddCardComposer').hasClass('is-open');

            if (willOpen && cardCount >= maxCards) {
                notify('error', 'حداکثر ' + maxCards + ' شماره کارت مجاز می‌باشد.');
                return;
            }
            setComposerOpen(willOpen);
        });

        $(document).off('click.mtwVault', '#cancelAddCardBtn').on('click.mtwVault', '#cancelAddCardBtn', function () {
            clearComposerFields();
            if ($('#bankCardsList .bank-card-item-ws').length > 0) {
                setComposerOpen(false);
            }
        });

        $(document).off('click.mtwVault', '.bank-card-remove-btn-ws, .bank-card-remove-btn').on('click.mtwVault', '.bank-card-remove-btn-ws, .bank-card-remove-btn', function (e) {
            e.stopPropagation();
            const $card = $(this).closest('.mtw-plastic, .bank-card-item-ws');
            clearRemoveConfirms();
            $card.addClass('is-confirming');
            $card.find('.mtw-plastic__confirm').prop('hidden', false);
        });

        $(document).off('click.mtwVaultSelect', '#bankCardsList .bank-card-item-ws, #bankCardsList .bank-card-item')
            .on('click.mtwVaultSelect', '#bankCardsList .bank-card-item-ws, #bankCardsList .bank-card-item', function (e) {
                if ($(e.target).closest('.bank-card-remove-btn-ws, .bank-card-remove-btn, .mtw-plastic__confirm, .mtw-plastic__confirm-yes, .mtw-plastic__confirm-no').length) {
                    return;
                }
                const $card = $(this);
                if ($card.hasClass('is-busy') || $card.hasClass('is-confirming')) {
                    return;
                }
                if ($card.hasClass('selected')) {
                    return;
                }
                selectDefaultBankCard($card);
                notify('success', 'کارت پیش‌فرض ذخیره شد');
            });

        $(document).off('keydown.mtwVaultSelect', '#bankCardsList .bank-card-item-ws, #bankCardsList .bank-card-item')
            .on('keydown.mtwVaultSelect', '#bankCardsList .bank-card-item-ws, #bankCardsList .bank-card-item', function (e) {
                if (e.key !== 'Enter' && e.key !== ' ' && e.keyCode !== 13 && e.keyCode !== 32) {
                    return;
                }
                e.preventDefault();
                $(this).trigger('click');
            });

        $(document).off('click.mtwVault', '.mtw-plastic__confirm-no').on('click.mtwVault', '.mtw-plastic__confirm-no', function (e) {
            e.stopPropagation();
            clearRemoveConfirms();
        });

        $(document).off('click.mtwVault', '.mtw-plastic__confirm-yes').on('click.mtwVault', '.mtw-plastic__confirm-yes', function (e) {
            e.stopPropagation();
            const cardId = $(this).attr('data-card-id');
            if (cardId) {
                removeCard(cardId);
            }
        });

        $(document).off('click.mtwVault', '#submitCardBtn').on('click.mtwVault', '#submitCardBtn', function () {
            if (typeof medyarBankCards === 'undefined') {
                return;
            }
            const $cardErr = $('#newCardNumberError');
            const $ibanErr = $('#newCardIbanError');
            const cardNumber = persianToEnglish($('#newCardNumber').val()).replace(/\D/g, '');
            const iban = normalizeIban($('#newCardIban').val());
            let hasError = false;

            $cardErr.text('');
            $ibanErr.text('');

            if (cardNumber.length !== 16) {
                $cardErr.text('شماره کارت باید ۱۶ رقم باشد.');
                hasError = true;
            }
            const ibanDigits = iban.replace(/^IR/, '');
            if (ibanDigits.length !== 24) {
                $ibanErr.text('شماره شبا باید دقیقاً ۲۴ رقم باشد.');
                hasError = true;
            } else if (!isValidIban(iban)) {
                $ibanErr.text('شماره شبا نامعتبر است.');
                hasError = true;
            }
            if (hasError) {
                notify('error', 'اطلاعات کارت را کامل و صحیح وارد کنید.');
                return;
            }

            const $btn = $(this).prop('disabled', true).text('در حال ذخیره…');
            postBankCards({
                action: medyarBankCards.addAction,
                nonce: medyarBankCards.nonce,
                card_number: cardNumber,
                iban: iban,
            })
                .done(function (response) {
                    if (response.success) {
                        if (response.data && response.data.cards) {
                            medyarBankCards.initialCards = response.data.cards;
                        }
                        clearComposerFields();
                        fetchBankCardsData();
                        setComposerOpen(false);
                        notify('success', (response.data && response.data.message) || 'ثبت شد');
                    } else {
                        const msg = (response.data && response.data.message) ? response.data.message : 'خطا';
                        if (msg.indexOf('شبا') !== -1) {
                            $ibanErr.text(msg);
                        } else {
                            $cardErr.text(msg);
                        }
                        notify('error', msg);
                    }
                })
                .fail(function () {
                    notify('error', 'خطا در ارتباط با سرور');
                })
                .always(function () {
                    $btn.prop('disabled', false).text('ذخیره کارت بانکی');
                });
        });
    };
})(jQuery);
