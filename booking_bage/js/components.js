(function () {
  'use strict';

  /* --- FAQ Accordion --- */
  var faqItems = document.querySelectorAll('.faq-item');
  faqItems.forEach(function (item) {
    var question = item.querySelector('.faq-item__question');
    if (!question) return;
    question.addEventListener('click', function () {
      var isOpen = item.classList.contains('is-open');
      /* Close all */
      faqItems.forEach(function (el) { el.classList.remove('is-open'); });
      if (!isOpen) item.classList.add('is-open');
    });
  });

  /* --- Stat Counters (animated) --- */
  function animateCounter(el, target, suffix) {
    suffix = suffix || '';
    var duration = 2000;
    var start = 0;
    var startTime = null;
    var prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (prefersReduced) {
      el.textContent = target + suffix;
      return;
    }

    function step(timestamp) {
      if (!startTime) startTime = timestamp;
      var progress = Math.min((timestamp - startTime) / duration, 1);
      var eased = 1 - Math.pow(1 - progress, 3);
      var current = Math.floor(eased * target);
      el.textContent = current + suffix;
      if (progress < 1) {
        requestAnimationFrame(step);
      } else {
        el.textContent = target + suffix;
      }
    }
    requestAnimationFrame(step);
  }

  var statNumbers = document.querySelectorAll('.stat-card__number');
  if (statNumbers.length > 0) {
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          var el = entry.target;
          var target = parseInt(el.getAttribute('data-target'), 10);
          var suffix = el.getAttribute('data-suffix') || '+';
          animateCounter(el, target, suffix);
          observer.unobserve(el);
        }
      });
    }, { threshold: 0.5 });

    statNumbers.forEach(function (el) { observer.observe(el); });
  }

  /* --- Service Card Hover: icon stroke color --- */
  var serviceIcons = document.querySelectorAll('.service-card__icon svg');
  serviceIcons.forEach(function (svg) {
    svg.style.stroke = 'var(--color-accent)';
  });

  /* --- Calendar Widget (used in booking.js) --- */
  window.ClinixCalendar = {
    render: function (container, options) {
      options = options || {};
      var today = new Date();
      today.setHours(0, 0, 0, 0);
      var selectedDate = options.selectedDate ? new Date(options.selectedDate) : null;
      var maxDays = options.maxDays || 30;
      var disabledDays = options.disabledDays || [];
      var onDateSelect = options.onDateSelect || function () {};

      var month = today.getMonth();
      var year = today.getFullYear();
      var containerEl = typeof container === 'string' ? document.querySelector(container) : container;
      if (!containerEl) return;

      function renderMonth(m, y) {
        var firstDay = new Date(y, m, 1);
        var lastDay = new Date(y, m + 1, 0);
        var startPad = firstDay.getDay();
        var daysInMonth = lastDay.getDate();

        var monthNames = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
        var dayNames = ['أحد','إثنين','ثلاثاء','أربعاء','خميس','جمعة','سبت'];

        var html = '<div class="cal-month-header">';
        html += '<button class="cal-nav cal-nav--prev" data-action="prev" aria-label="الشهر السابق">';
        html += '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>';
        html += '</button>';
        html += '<span class="cal-month-label">' + monthNames[m] + ' ' + y + '</span>';
        html += '<button class="cal-nav cal-nav--next" data-action="next" aria-label="الشهر التالي">';
        html += '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>';
        html += '</button>';
        html += '</div>';

        html += '<div class="cal-days-header">';
        dayNames.forEach(function (d) {
          html += '<div class="cal-day-name">' + d + '</div>';
        });
        html += '</div>';

        html += '<div class="cal-grid">';
        for (var i = 0; i < startPad; i++) {
          html += '<div class="cal-day cal-day--empty"></div>';
        }
        for (var d = 1; d <= daysInMonth; d++) {
          var date = new Date(y, m, d);
          var isPast = date < today;
          var isFriday = date.getDay() === 5;
          var isDisabled = disabledDays.some(function (dd) {
            return dd.getFullYear() === y && dd.getMonth() === m && dd.getDate() === d;
          });
          var isSelected = selectedDate && selectedDate.getFullYear() === y && selectedDate.getMonth() === m && selectedDate.getDate() === d;
          var cls = 'cal-day';
          if (isPast || isDisabled) cls += ' cal-day--disabled';
          if (isSelected) cls += ' cal-day--selected';
          if (isFriday) cls += ' cal-day--friday';
          html += '<button class="' + cls + '" data-date="' + y + '-' + String(m+1).padStart(2,'0') + '-' + String(d).padStart(2,'0') + '" ' + (isPast || isDisabled ? 'disabled' : '') + ' aria-label="' + d + ' ' + monthNames[m] + '">' + d + '</button>';
        }
        html += '</div>';
        return html;
      }

      containerEl.innerHTML = renderMonth(month, year);

      containerEl.querySelectorAll('.cal-nav').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var action = this.getAttribute('data-action');
          if (action === 'prev') {
            month--;
            if (month < 0) { month = 11; year--; }
          } else {
            month++;
            if (month > 11) { month = 0; year++; }
          }
          containerEl.innerHTML = renderMonth(month, year);
          containerEl.querySelectorAll('.cal-day:not(.cal-day--disabled)').forEach(function (dayBtn) {
            dayBtn.addEventListener('click', function () {
              containerEl.querySelectorAll('.cal-day').forEach(function (d) { d.classList.remove('cal-day--selected'); });
              this.classList.add('cal-day--selected');
              selectedDate = new Date(this.getAttribute('data-date'));
              onDateSelect(selectedDate, this.getAttribute('data-date'));
            });
          });
        });
      });

      containerEl.querySelectorAll('.cal-day:not(.cal-day--disabled)').forEach(function (dayBtn) {
        dayBtn.addEventListener('click', function () {
          containerEl.querySelectorAll('.cal-day').forEach(function (d) { d.classList.remove('cal-day--selected'); });
          this.classList.add('cal-day--selected');
          selectedDate = new Date(this.getAttribute('data-date'));
          onDateSelect(selectedDate, this.getAttribute('data-date'));
        });
      });
    }
  };

})();
