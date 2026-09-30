/**
 * The log screen: a row's detail in a slideout, older pages appended, and "show more" on a long
 * value. Listeners are delegated from the document, so rows appended later and the slideout's own
 * contents (which live outside the page) need no wiring of their own.
 *
 * Every piece of HTML inserted here was rendered, and escaped, by the server's templates.
 */
(function() {
  'use strict';

  let slideout = null;

  function openEvent(id) {
    Craft.sendActionRequest('GET', 'auditor/events/view', {params: {id: id}})
      .then(function(response) {
        if (slideout) {
          slideout.close();
        }
        slideout = new Craft.Slideout(response.data, {
          containerAttributes: {class: 'auditor-slideout'},
        });
        slideout.on('close', function() {
          const closed = slideout;
          slideout = null;
          setTimeout(function() { closed.destroy(); }, 300);
        });
      })
      .catch(function() {
        Craft.cp.displayError(Craft.t('auditor', 'This event could not be loaded.'));
      });
  }

  function loadOlder(button) {
    const params = Object.assign({}, JSON.parse(button.dataset.filter || '{}'), {after: button.dataset.after});
    button.classList.add('loading');
    button.disabled = true;

    Craft.sendActionRequest('GET', 'auditor/events/index', {params: params})
      .then(function(response) {
        document.querySelector('[data-auditor-rows]').insertAdjacentHTML('beforeend', response.data.html);
        if (response.data.after) {
          button.dataset.after = response.data.after;
          button.disabled = false;
        } else {
          button.parentNode.remove();
        }
      })
      .catch(function() {
        button.disabled = false;
        Craft.cp.displayError(Craft.t('auditor', 'Older events could not be loaded.'));
      })
      .finally(function() {
        button.classList.remove('loading');
      });
  }

  function toggleMore(button) {
    const value = button.closest('[data-auditor-truncated]');
    const expanded = button.getAttribute('aria-expanded') === 'true';
    value.querySelector('.auditor-rest').classList.toggle('hidden', expanded);
    value.querySelector('.auditor-ellipsis').classList.toggle('hidden', !expanded);
    button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
    button.textContent = expanded ? button.dataset.more : button.dataset.less;
  }

  document.addEventListener('click', function(event) {
    const target = event.target.closest('[data-auditor-open], [data-auditor-older], [data-auditor-more], [data-auditor-close]');
    if (!target) {
      return;
    }
    event.preventDefault();

    if (target.hasAttribute('data-auditor-open')) {
      openEvent(target.dataset.auditorOpen);
    } else if (target.hasAttribute('data-auditor-older')) {
      loadOlder(target);
    } else if (target.hasAttribute('data-auditor-more')) {
      toggleMore(target);
    } else if (slideout) {
      slideout.close();
    }
  });
})();
