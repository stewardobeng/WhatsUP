'use strict';
document.addEventListener('DOMContentLoaded', () => {
  const sidebar = document.querySelector('#sidebar');
  const toggle = document.querySelector('.sidebar-toggle');
  const backdrop = document.querySelector('[data-sidebar-backdrop]');
  const closeButton = document.querySelector('[data-sidebar-close]');
  const mobileQuery = window.matchMedia('(max-width: 900px)');

  if (sidebar && toggle) {
    const isOpen = () => sidebar.classList.contains('is-open');

    const setOpen = (open, { restoreFocus = false } = {}) => {
      sidebar.classList.toggle('is-open', open);
      document.body.classList.toggle('sidebar-open', open);
      toggle.setAttribute('aria-expanded', String(open));
      if (backdrop) backdrop.hidden = !open;
      if (open && closeButton) closeButton.focus();
      if (!open && restoreFocus) toggle.focus();
    };

    toggle.addEventListener('click', () => setOpen(!isOpen()));

    if (closeButton) {
      closeButton.addEventListener('click', () => setOpen(false, { restoreFocus: true }));
    }
    if (backdrop) {
      backdrop.addEventListener('click', () => setOpen(false, { restoreFocus: true }));
    }

    // Escape closes the drawer and returns focus to the trigger.
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && isOpen()) {
        setOpen(false, { restoreFocus: true });
      }
    });

    // Close after choosing a destination so the drawer never covers the new page.
    sidebar.querySelectorAll('.sidebar-nav a').forEach((link) => {
      link.addEventListener('click', () => {
        if (mobileQuery.matches) setOpen(false);
      });
    });

    // Reset state when the viewport grows into the permanent-sidebar layout.
    const syncLayout = () => {
      if (!mobileQuery.matches) {
        setOpen(false);
      }
    };
    if (typeof mobileQuery.addEventListener === 'function') {
      mobileQuery.addEventListener('change', syncLayout);
    } else if (typeof mobileQuery.addListener === 'function') {
      mobileQuery.addListener(syncLayout);
    }
  }

  document.querySelectorAll('[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (!window.confirm(form.getAttribute('data-confirm') || 'Are you sure?')) {
        event.preventDefault();
      }
    });
  });

  document.querySelectorAll('[data-toggle-panel]').forEach((button) => {
    button.addEventListener('click', () => {
      const panel = document.querySelector(`[data-panel="${CSS.escape(button.dataset.togglePanel)}"]`);
      if (panel) {
        panel.hidden = !panel.hidden;
        if (!panel.hidden) panel.querySelector('input,select,textarea,button')?.focus();
      }
    });
  });
});
