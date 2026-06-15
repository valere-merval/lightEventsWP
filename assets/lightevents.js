(function(){
  function formToPayload(form, submitter){
    var data = new FormData(form);
    if (submitter && submitter.name) data.set(submitter.name, submitter.value);
    return data;
  }
  document.addEventListener('submit', function(event){
    var form = event.target.closest('[data-lightevents-checkout]');
    if (!form) return;
    event.preventDefault();
    var message = form.querySelector('.lightevents-form-message');
    var submitter = event.submitter || form.querySelector('button[type="submit"]');
    if (message) { message.className = 'lightevents-form-message'; message.textContent = 'Traitement en cours…'; }
    fetch((window.LightEventsWP && LightEventsWP.ajaxUrl) || '/wp-admin/admin-ajax.php', { method: 'POST', body: formToPayload(form, submitter), credentials: 'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(res){
        if (!res || !res.success) throw new Error((res && res.data && res.data.message) || 'Erreur LightEvents');
        var checkout = res.data.checkout || {};
        if (checkout.checkoutUrl) { window.location.href = checkout.checkoutUrl; return; }
        if (message) { message.className = 'lightevents-form-message success'; message.textContent = res.data.message || 'Réservation enregistrée.'; }
      })
      .catch(function(err){ if (message) { message.className = 'lightevents-form-message error'; message.textContent = err.message || 'Erreur.'; } });
  });
})();
