(function(){
  function formToPayload(form, submitter){
    var data = new FormData(form);
    if (submitter && submitter.name) data.set(submitter.name, submitter.value);
    return data;
  }
  function money(value, currency){
    try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'XOF' }).format(value || 0); }
    catch(e){ return (value || 0).toFixed(2) + ' ' + (currency || 'XOF'); }
  }
  function updateTotal(form){
    var select = form.querySelector('select[name="ticketTypeId"]');
    var qty = form.querySelector('input[name="quantity"]');
    var total = form.querySelector('[data-lightevents-total]');
    if (!select || !qty || !total) return;
    var option = select.options[select.selectedIndex];
    var price = parseFloat(option && option.getAttribute('data-price') || '0');
    var currency = option && option.getAttribute('data-currency') || (window.LightEventsWP && LightEventsWP.currency) || 'XOF';
    var amount = price * Math.max(1, parseInt(qty.value || '1', 10));
    total.textContent = 'Total estimé: ' + money(amount, currency) + ' — frais LightEvents transparents inclus.';
  }
  document.addEventListener('change', function(event){
    var form = event.target.closest('[data-lightevents-checkout]');
    if (form) updateTotal(form);
  });
  document.addEventListener('input', function(event){
    var form = event.target.closest('[data-lightevents-checkout]');
    if (form && event.target.name === 'quantity') updateTotal(form);
  });
  document.querySelectorAll('[data-lightevents-checkout]').forEach(updateTotal);
  document.addEventListener('submit', function(event){
    var form = event.target.closest('[data-lightevents-checkout]');
    if (!form) return;
    event.preventDefault();
    var message = form.querySelector('.lightevents-form-message');
    var buttons = Array.prototype.slice.call(form.querySelectorAll('button[type="submit"]'));
    var submitter = event.submitter || buttons[0];
    buttons.forEach(function(btn){ btn.disabled = true; btn.classList.add('is-loading'); });
    if (message) { message.className = 'lightevents-form-message'; message.textContent = 'Traitement sécurisé en cours…'; }
    fetch((window.LightEventsWP && LightEventsWP.ajaxUrl) || '/wp-admin/admin-ajax.php', { method: 'POST', body: formToPayload(form, submitter), credentials: 'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(res){
        if (!res || !res.success) throw new Error((res && res.data && res.data.message) || 'Erreur LightEvents');
        var checkout = res.data.checkout || {};
        if (checkout.checkoutUrl) { window.location.href = checkout.checkoutUrl; return; }
        if (message) { message.className = 'lightevents-form-message success'; message.textContent = res.data.message || 'Réservation enregistrée.'; }
        form.dispatchEvent(new CustomEvent('lightevents:checkout-success', { detail: res.data }));
      })
      .catch(function(err){ if (message) { message.className = 'lightevents-form-message error'; message.textContent = err.message || 'Erreur.'; } })
      .finally(function(){ buttons.forEach(function(btn){ btn.disabled = false; btn.classList.remove('is-loading'); }); });
  });
})();
