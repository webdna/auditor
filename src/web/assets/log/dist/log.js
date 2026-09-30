/**
 * The log screen, an element's History and a user's Activity: a row's detail in a slideout, older
 * pages appended to the list they belong to (each Activity tab is one), "show more" on a long
 * value, and the History panel opened from an element's action menu.
 * Listeners are delegated from the document, so rows appended later and the slideouts' contents
 * (which live outside the page) need no wiring of their own.
 *
 * Every piece of HTML inserted here was rendered, and escaped, by the server's templates. The
 * History panel is fetched when its menu item is chosen, never with the edit screen (BR-37).
 */
(function() {
  'use strict';

  // The History panel, and a row's detail, which opens over it.
  let history = null;
  let detail = null;

  function open(html, onClose) {
    const slideout = new Craft.Slideout(html, {
      containerAttributes: {class: 'auditor-slideout'},
    });
    slideout.on('close', function() {
      onClose();
      setTimeout(function() { slideout.destroy(); }, 300);
    });

    return slideout;
  }

  function openEvent(button) {
    const params = Object.assign({}, JSON.parse(button.dataset.auditorParams || '{}'), {id: button.dataset.auditorOpen});

    Craft.sendActionRequest('GET', button.dataset.auditorView || 'auditor/events/view', {params: params})
      .then(function(response) {
        if (detail) {
          detail.close();
        }
        const opened = open(response.data, function() {
          if (detail === opened) {
            detail = null;
          }
        });
        detail = opened;
      })
      .catch(function() {
        Craft.cp.displayError(Craft.t('auditor', 'This event could not be loaded.'));
      });
  }

  function openHistory(button) {
    const params = {elementId: button.dataset.elementId, siteId: button.dataset.siteId};

    Craft.sendActionRequest('GET', 'auditor/history/index', {params: params})
      .then(function(response) {
        if (history) {
          history.close();
        }
        const opened = open(response.data, function() {
          if (history === opened) {
            history = null;
          }
        });
        history = opened;
      })
      .catch(function() {
        Craft.cp.displayError(Craft.t('auditor', 'The history could not be loaded.'));
      });
  }

  function loadOlder(button) {
    const params = Object.assign({}, JSON.parse(button.dataset.filter || '{}'), {after: button.dataset.after});
    const list = button.closest('[data-auditor-list]') || document;
    button.classList.add('loading');
    button.disabled = true;

    Craft.sendActionRequest('GET', button.dataset.action || 'auditor/events/index', {params: params})
      .then(function(response) {
        list.querySelector('[data-auditor-rows]').insertAdjacentHTML('beforeend', response.data.html);
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

  function close(button) {
    const slideout = button.closest('[data-auditor-list]') ? history : detail;
    if (slideout) {
      slideout.close();
    }
  }

  document.addEventListener('click', function(event) {
    const target = event.target.closest('[data-auditor-open], [data-auditor-older], [data-auditor-more], [data-auditor-close], [data-auditor-history]');
    if (!target) {
      return;
    }
    event.preventDefault();

    if (target.hasAttribute('data-auditor-open')) {
      openEvent(target);
    } else if (target.hasAttribute('data-auditor-older')) {
      loadOlder(target);
    } else if (target.hasAttribute('data-auditor-more')) {
      toggleMore(target);
    } else if (target.hasAttribute('data-auditor-history')) {
      openHistory(target);
    } else {
      close(target);
    }
  });
})();
