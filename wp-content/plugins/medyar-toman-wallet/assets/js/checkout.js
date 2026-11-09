/**
 * Wallet checkout UX:
 * - Checkbox on → auto-fill max usable amount and refresh fees/gateways
 * - Soft loading only while WE trigger update_checkout
 * - Wallet payment method radio is never shown (PHP hides it)
 * - "تمام مبلغ" fills max usable wallet amount
 * - Order-pay: toggle UI only (debit on form submit)
 * - Uses mtw_* field names so theme Sheyda PartialPayment never reserves/locks balance
 */
(function ($) {
  "use strict";

  var doneTimer = null;
  var inputTimer = null;
  var autoMaxPending = false;

  function $review() {
    return $("#order_review-wrap, .mtw-order-pay-wallet-wrap").first();
  }

  function $wallet() {
    return $(".sheyda-wallet-partial[data-mtw-wallet]").first();
  }

  function walletChecked() {
    return $wallet().find('input[name="mtw_use_wallet"]').is(":checked");
  }

  function walletAmountValue() {
    var raw = String($wallet().find("#mtw_wallet_amount").val() || "");
    var map = {
      "۰": "0", "۱": "1", "۲": "2", "۳": "3", "۴": "4",
      "۵": "5", "۶": "6", "۷": "7", "۸": "8", "۹": "9",
      "٠": "0", "١": "1", "٢": "2", "٣": "3", "٤": "4",
      "٥": "5", "٦": "6", "٧": "7", "٨": "8", "٩": "9"
    };
    raw = raw.replace(/[۰-۹٠-٩]/g, function (d) {
      return map[d] || d;
    });
    var digits = raw.replace(/\D+/g, "");
    return digits ? parseInt(digits, 10) || 0 : 0;
  }

  function walletHasAmount() {
    return walletAmountValue() > 0;
  }

  function feeCurrentlyApplied() {
    return (
      $("#order_review tr.fee, #order_review-wrap tr.fee")
        .filter(function () {
          return $(this).text().indexOf("پرداخت از کیف پول") !== -1;
        }).length > 0
    );
  }

  /**
   * True when checkout should recalculate wallet fee.
   */
  function shouldProcessWalletUpdate($el) {
    if (!$wallet().length) {
      return false;
    }
    if ($el && $el.is('input[name="mtw_use_wallet"]') && !$el.is(":checked")) {
      return feeCurrentlyApplied();
    }
    if (!walletChecked()) {
      return false;
    }
    if (walletHasAmount()) {
      return true;
    }
    return feeCurrentlyApplied();
  }

  function formatAmount(value) {
    var raw = String(value == null ? "" : value);
    if (typeof sheydaWallet !== "undefined" && sheydaWallet.formatPrice) {
      return sheydaWallet.formatPrice(raw);
    }
    // Fallback: FA digits + strip ASCII/Persian grouping (never toLocaleString).
    if (typeof sheydaWallet !== "undefined" && sheydaWallet.convertChars) {
      raw = sheydaWallet.convertChars(raw);
    } else {
      raw = raw.replace(/[۰-۹]/g, function (d) {
        return "۰۱۲۳۴۵۶۷۸۹".indexOf(d);
      }).replace(/[٠-٩]/g, function (d) {
        return "٠١٢٣٤٥٦٧٨٩".indexOf(d);
      });
    }
    var digits = raw.replace(/[\s,\u066C\u202F\u00A0'’]/g, "").replace(/\D+/g, "").replace(/^0+(?=\d)/, "");
    if (!digits) {
      return "";
    }
    return digits.replace(/\B(?=(\d{3})+(?!\d))/g, ",");
  }

  function applyAmountInput(el) {
    if (!el) {
      return;
    }
    if (typeof sheydaWallet !== "undefined" && typeof sheydaWallet.applyPriceInput === "function") {
      sheydaWallet.applyPriceInput(el);
      return;
    }
    var raw = el.value;
    var caret = typeof el.selectionStart === "number" ? el.selectionStart : raw.length;
    var before = String(raw).slice(0, caret).replace(/[^\d۰-۹٠-٩]/g, "").length;
    var formatted = formatAmount(raw);
    if (formatted === raw) {
      return;
    }
    el.value = formatted;
    if (typeof el.setSelectionRange !== "function") {
      return;
    }
    var pos = 0;
    var seen = 0;
    while (pos < formatted.length && seen < before) {
      if (/\d/.test(formatted.charAt(pos))) {
        seen++;
      }
      pos++;
    }
    try {
      el.setSelectionRange(pos, pos);
    } catch (e) { /* ignore */ }
  }

  function showCalculating() {
    var $form = $("form.woocommerce-checkout");
    $form.addClass("mtw-wallet-updating");
    $("#order_review-wrap").addClass("mtw-is-loading");
    var $row = $wallet();
    if ($row.length && !$row.find(".mtw-wallet-status").length) {
      $row.find(".sheyda-wallet-partial-form").append(
        '<div class="mtw-wallet-status" aria-live="polite">در حال محاسبه…</div>'
      );
    }
  }

  function clearCalcUiSilent() {
    $("form.woocommerce-checkout").removeClass("mtw-wallet-updating");
    $("#order_review-wrap").removeClass("mtw-is-loading");
    $wallet().find(".mtw-wallet-status").remove();
  }

  function showDoneThenClear() {
    clearTimeout(doneTimer);
    doneTimer = setTimeout(clearCalcUiSilent, 180);
  }

  function triggerWalletCheckoutUpdate() {
    stripThemeWalletDupes();
    showCalculating();
    $(document.body).trigger("update_checkout");
  }

  /**
   * Theme Sheyda still injects a hidden .sheyda-wallet-partial without data-mtw-wallet.
   * CSS hides it, but its empty amount input still serializes and overwrites ours in POST.
   */
  function stripThemeWalletDupes() {
    $(".sheyda-wallet-partial")
      .not("[data-mtw-wallet]")
      .each(function () {
        $(this)
          .find("input, select, textarea")
          .prop("disabled", true)
          .removeAttr("name")
          .removeAttr("id");
        $(this).remove();
      });

    // Legacy Sheyda field names must never reach place-order (theme locks on them).
    $('input[name="sheyda_wallet_partial_amount"], input[name="sheyda_wallet_use_wallet"]').each(function () {
      $(this).prop("disabled", true).removeAttr("name").removeAttr("id");
    });
  }

  /**
   * After refresh: if payment methods remain (partial) and none selected, pick the first.
   * Full wallet cover removes the payment list entirely (needs_payment=false).
   */
  function syncPaymentTitleVisibility() {
    var $payment = $("#payment");
    if (!$payment.length) {
      return;
    }
    var hasMethods = $payment.find(".wc_payment_methods").length > 0;
    $payment.toggleClass("mtw-wallet-covers-all", !hasMethods);
  }

  function ensurePaymentMethodSelected() {
    syncPaymentTitleVisibility();
    var $methods = $('input[name="payment_method"]').filter(function () {
      return this.value !== "wallet";
    });
    if (!$methods.length) {
      return;
    }
    if (!$methods.filter(":checked").length) {
      $methods.first().prop("checked", true).trigger("click").trigger("change");
    }
  }

  function applyMaxAmount($row, opts) {
    opts = opts || {};
    var $amount = $row.find("#mtw_wallet_amount");
    var $checkbox = $row.find('input[name="mtw_use_wallet"]');
    var max = parseInt($amount.attr("data-max") || "0", 10) || 0;
    if (max <= 0) {
      return false;
    }

    if (!$checkbox.is(":checked")) {
      $checkbox.prop("checked", true);
    }

    $row.addClass("is-active");
    $row.find(".sheyda-wallet-partial-field").show();
    $amount.prop("disabled", false).val(formatAmount(max));

    if (!opts.skipUpdate && (!walletCheckout || walletCheckout.context !== "order-pay")) {
      triggerWalletCheckoutUpdate();
    }
    return true;
  }

  function bindMaxButton() {
    $(document).on("click.mtwMax", "[data-mtw-wallet-max]", function (e) {
      e.preventDefault();
      var $row = $(this).closest(".sheyda-wallet-partial[data-mtw-wallet]");
      if (!$row.length || $(this).is(":disabled")) {
        return;
      }
      applyMaxAmount($row);
    });
  }

  /**
   * Own the Sheyda row so theme partial-payment.js cannot keep firing update_checkout.
   * Always rebind after updated_checkout (AJAX replaces nodes).
   */
  function bindSmartWalletCheckout() {
    if (typeof walletCheckout === "undefined") {
      return;
    }
    if ((walletCheckout.context || "checkout") === "order-pay") {
      return;
    }

    var $wrap = $(".sheyda-wallet-partial[data-mtw-wallet]");
    if (!$wrap.length) {
      return;
    }

    // Preempt theme bindWallet (checks this flag).
    $wrap.data("wallet-bound", true);

    var $checkbox = $wrap.find('input[name="mtw_use_wallet"]');
    var $amount = $wrap.find("#mtw_wallet_amount");
    var $field = $wrap.find(".sheyda-wallet-partial-field");

    // Strip theme/anonymous handlers that always call update_checkout.
    $checkbox.off("change");
    $amount.off("input");

    var syncUi = function () {
      var active = $checkbox.is(":checked");
      $wrap.toggleClass("is-active", active);
      if (!active) {
        $amount.val("");
        $field.hide();
      } else {
        $field.show();
      }
    };

    $checkbox.on("change.mtwSmart", function () {
      syncUi();

      // Check → auto-fill max usable amount, then refresh cart/gateways.
      if ($checkbox.is(":checked") && !walletHasAmount()) {
        if (applyMaxAmount($wrap)) {
          return;
        }
        clearCalcUiSilent();
        return;
      }

      if (!shouldProcessWalletUpdate($(this))) {
        clearCalcUiSilent();
        return;
      }
      showCalculating();
      clearTimeout(inputTimer);
      inputTimer = setTimeout(triggerWalletCheckoutUpdate, 420);
    });

    $amount.on("input.mtwSmart", function () {
      var $el = $(this);
      applyAmountInput($el[0]);
      clearTimeout(inputTimer);
      inputTimer = setTimeout(function () {
        if (!shouldProcessWalletUpdate($el)) {
          clearCalcUiSilent();
          return;
        }
        triggerWalletCheckoutUpdate();
      }, 480);
    });

    syncUi();

    // Recover broken state: checked + empty amount (e.g. after soft refresh).
    if ($checkbox.is(":checked") && !walletHasAmount() && !autoMaxPending) {
      autoMaxPending = true;
      setTimeout(function () {
        autoMaxPending = false;
        var $row = $wallet();
        if ($row.length && $row.find('input[name="mtw_use_wallet"]').is(":checked") && !walletHasAmount()) {
          applyMaxAmount($row);
        }
      }, 60);
    }
  }

  function bindCheckoutLoading() {
    // Only show wallet loading chrome when WE started a wallet fee refresh.
    // Never react to generic update_checkout (address/shipping/page load).
    $(document.body).on("updated_checkout.mtwLoad", function () {
      // Strip any leftover wallet radio nodes from cached fragments.
      $("li.wc_payment_method.payment_method_wallet, #payment_method_wallet").closest("li").addBack().filter("li.payment_method_wallet").remove();
      $("#payment_method_wallet").closest("li").remove();
      stripThemeWalletDupes();

      if (!$wallet().length) {
        ensurePaymentMethodSelected();
        return;
      }
      // Reclaim row immediately so theme bindWallet no-ops on the new fragment.
      bindSmartWalletCheckout();
      ensurePaymentMethodSelected();
      if ($("form.woocommerce-checkout").hasClass("mtw-wallet-updating")) {
        showDoneThenClear();
      } else {
        clearCalcUiSilent();
      }
    });

    // update_order_review failures never fire updated_checkout — don't leave the panel locked.
    $(document).on("ajaxError.mtwLoad", function (e, xhr, settings) {
      if (settings && String(settings.url || "").indexOf("update_order_review") !== -1) {
        clearCalcUiSilent();
      }
    });
  }

  function bindOrderPayUi() {
    if (typeof walletCheckout === "undefined") {
      return;
    }
    if ((walletCheckout.context || "checkout") !== "order-pay") {
      return;
    }

    var $wrap = $(".sheyda-wallet-partial[data-mtw-wallet][data-context='order-pay']");
    if (!$wrap.length || $wrap.data("mtw-bound")) {
      return;
    }
    $wrap.data("mtw-bound", true);
    $wrap.data("wallet-bound", true);

    var $checkbox = $wrap.find('input[name="mtw_use_wallet"]');
    var $amount = $wrap.find('input[name="mtw_wallet_amount"]');
    var $field = $wrap.find(".sheyda-wallet-partial-field");

    var syncUi = function () {
      var active = $checkbox.is(":checked");
      $wrap.toggleClass("is-active", active);
      if (!active) {
        $amount.val("");
        $field.hide();
      } else {
        $field.show();
        if (!walletAmountValue()) {
          applyMaxAmount($wrap, { skipUpdate: true });
        }
      }
    };

    $checkbox.on("change", syncUi);
    $amount.on("input", function () {
      applyAmountInput(this);
    });

    syncUi();
  }

  $(function () {
    if (typeof walletCheckout === "undefined") {
      return;
    }
    // Hard-remove wallet gateway radio if anything still rendered it.
    $("li.wc_payment_method.payment_method_wallet").remove();
    $("#payment_method_wallet").closest("li").remove();
    stripThemeWalletDupes();

    bindCheckoutLoading();
    bindMaxButton();
    bindSmartWalletCheckout();
    // Theme partial-payment.js may bind after us on the same ready tick — reclaim next macrotask.
    setTimeout(function () {
      stripThemeWalletDupes();
      bindSmartWalletCheckout();
    }, 0);
    bindOrderPayUi();
    setTimeout(ensurePaymentMethodSelected, 80);
  });
})(jQuery);
