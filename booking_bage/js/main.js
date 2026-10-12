(function () {
  'use strict';

  /* --- Mobile Menu --- */
  const hamburger = document.querySelector('.hamburger');
  const navDrawer = document.querySelector('.nav-drawer');
  const overlay = document.querySelector('.nav-overlay');

  function openMenu() {
    if (!navDrawer || !overlay) return;
    navDrawer.classList.add('is-open');
    overlay.classList.add('is-visible');
    document.body.classList.add('nav-open');
    if (hamburger) hamburger.classList.add('is-active');
  }

  function closeMenu() {
    if (!navDrawer || !overlay) return;
    navDrawer.classList.remove('is-open');
    overlay.classList.remove('is-visible');
    document.body.classList.remove('nav-open');
    if (hamburger) hamburger.classList.remove('is-active');
  }

  if (hamburger) {
    hamburger.addEventListener('click', function () {
      navDrawer.classList.contains('is-open') ? closeMenu() : openMenu();
    });
  }

  if (overlay) {
    overlay.addEventListener('click', closeMenu);
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && navDrawer && navDrawer.classList.contains('is-open')) {
      closeMenu();
      if (hamburger) hamburger.focus();
    }
  });

  /* --- Dropdown Toggle --- */
  var dropdowns = document.querySelectorAll('.nav-dropdown');
  dropdowns.forEach(function (dd) {
    var trigger = dd.querySelector('.nav-dropdown__trigger');
    if (!trigger) return;
    trigger.addEventListener('click', function (e) {
      e.stopPropagation();
      var isOpen = dd.classList.contains('is-open');
      dropdowns.forEach(function (d) { d.classList.remove('is-open'); });
      if (!isOpen) dd.classList.add('is-open');
    });
    dd.addEventListener('click', function (e) {
      e.stopPropagation();
    });
  });
  document.addEventListener('click', function () {
    dropdowns.forEach(function (d) { d.classList.remove('is-open'); });
  });

  /* --- Scroll: header shadow on scroll --- */
  var header = document.querySelector('.site-header');
  if (header) {
    var lastScroll = 0;
    window.addEventListener('scroll', function () {
      var current = window.pageYOffset;
      if (current > 10) {
        header.style.boxShadow = '0 2px 16px rgba(0,0,0,0.1)';
      } else {
        header.style.boxShadow = 'var(--shadow-sm)';
      }
      lastScroll = current;
    }, { passive: true });
  }

  /* --- Smooth scroll for anchor links --- */
  document.querySelectorAll('a[href^="#"]').forEach(function (link) {
    link.addEventListener('click', function (e) {
      var targetId = link.getAttribute('href');
      if (targetId === '#') return;
      var target = document.querySelector(targetId);
      if (target) {
        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        closeMenu();
      }
    });
  });

})();
