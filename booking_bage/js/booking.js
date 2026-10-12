(function () {
  'use strict';

  /* --- Doctor Data --- */
  var doctors = [
    {
      id: 1,
      name: 'د. أحمد الحربي',
      specialty: 'استشاري الطب العام',
      experience: '+10 سنوات',
      languages: ['العربية', 'الإنجليزية'],
      services: ['استشارة طبية عامة', 'فحص طبي شامل', 'متابعة طبية'],
      image: 'images/doctor-portrait-1.webp',
      bio: 'استشاري الطب العام بخبرة تزيد عن عشر سنوات في تشخيص وعلاج الأمراض الشائعة والمزمنة. حاصل على البورد السعودي في الطب الباطني والعام، يعمل على تقديم رعاية صحية متكاملة لجميع الفئات العمرية.',
      qualifications: ['البورد السعودي في الطب العام', 'زمالة الكلية الملكية للأطباء (MRCP)', 'عضو الجمعية السعودية للأمراض الباطنية'],
      expertise: ['الطب العام', 'الأمراض المزمنة', 'فحوصات دورية', 'التوعية الصحية'],
      hours: [{ day: 'الأحد - الخميس', time: '9:00 ص - 10:00 م' }, { day: 'السبت', time: '10:00 ص - 8:00 م' }]
    },
    {
      id: 2,
      name: 'د. سارة المنصور',
      specialty: 'استشارية طب الأطفال',
      experience: '+8 سنوات',
      languages: ['العربية', 'الإنجليزية'],
      services: ['استشارة أطفال', 'تطعيمات', 'نمو وتطور'],
      image: 'images/doctor-portrait-2.webp',
      bio: 'استشارية طب الأطفال بخبرة ثماني سنوات في رعاية صحة الأطفال من الرضاعة حتى المراهقة. متخصصة في التطعيمات ومتابعة النمو والتطور مع نهج يركز على عائلة الطفل.',
      qualifications: ['البورد السعودي في طب الأطفال', 'زمالة أمريكية في طب الأطفال (FAAP)', 'دبلوم صحة الطفل'],
      expertise: ['طب الأطفال', 'التطعيمات', 'غذاء الأطفال', 'نمو وتطور'],
      hours: [{ day: 'الأحد - الخميس', time: '10:00 ص - 8:00 م' }, { day: 'السبت', time: '10:00 ص - 4:00 م' }]
    },
    {
      id: 3,
      name: 'د. عمر الراشد',
      specialty: 'استشاري الجلدية والتجميل',
      experience: '+12 سنة',
      languages: ['العربية', 'الإنجليزية'],
      services: ['استشارة جلدية', 'علاج أمراض الجلدة', 'تجميل'],
      image: 'images/doctor-portrait-3.webp',
      bio: 'استشاري الجلدية والتجميل بخبرة تزيد عن اثنتي عشرة سنة. متخصص في علاج أمراض الجلدة الشائعة والمعقدة وإجراءات التجميل غير الجراحية باستخدام أحدث التقنيات.',
      qualifications: ['البورد العربي في الأمراض الجلدية والتناسلية', 'زمالة ألمانية في الجلدية (DMVD)', 'شهادة في التجميل الإجرائية'],
      expertise: ['أمراض الجلدة', 'التجميل', 'علاج الصدفية', 'زراعة الشعر'],
      hours: [{ day: 'الأحد - الخميس', time: '12:00 م - 10:00 م' }, { day: 'الجمعة', time: '4:00 م - 10:00 م' }]
    },
    {
      id: 4,
      name: 'د. لينا القحطاني',
      specialty: 'استشارية النساء والولادة',
      experience: '+9 سنوات',
      languages: ['العربية', 'الإنجليزية'],
      services: ['استشارة نساء', 'متابعة حمل', 'فحوصات'],
      image: 'images/doctor-portrait-4.webp',
      bio: 'استشارية النساء والولادة بخبرة تزيد عن تسع سنوات. متخصصة في رعاية ما قبل الولادة والمتابعة الصحية للحمل الصحي مع التركيز على تجربة ولادة آمنة ومريحة.',
      qualifications: ['البورد السعودي في النساء والولادة', 'زمالة أمريكية في التوليد والنساء (FACOG)', 'دبلوم صحة المرأة'],
      expertise: ['رعاية ما قبل الولادة', 'الولادة الطبيعية', 'صحة المرأة', 'فحوصات النساء'],
      hours: [{ day: 'الأحد - الخميس', time: '9:00 ص - 6:00 م' }, { day: 'السبت', time: '10:00 ص - 2:00 م' }]
    }
  ];

  /* --- Services Data --- */
  var services = [
    { id: 1, name: 'استشارة طبية عامة', specialty: 'الطب العام', duration: '30 دقيقة', icon: 'icon-stethoscope', doctor: 1, description: 'استشارة شاملة مع طبيب عام لتشخيص الأعراض، وضع خطة علاجية، ومتابعة الحالات المزمنة. مناسبة لجميع الفئات العمرية.', benefits: ['تشخيص دقيق', 'خطة علاجية مخصصة', 'متابعة مستمرة'], suitable: 'جميع الأعمار، حالات المرض المزمن', prep: 'لا يوجد استعداد مسبق — أحضر أي أدوية تتناولها حالياً.' },
    { id: 2, name: 'استشارة طب الأطفال', specialty: 'طب الأطفال', duration: '30 دقيقة', icon: 'icon-child', doctor: 2, description: 'زيارة تخصصية لطفلك مع استشارية طب الأطفال لفحص شامل، متابعة النمو، والتطعيمات.', benefits: ['فحص نمو وتطور شامل', 'جدول تطعيمات محدّث', 'نصائح تغذية'], suitable: 'الأطفال من المواليد حتى 18 سنة', prep: 'أحضر دفتر التطعيمات إن وجد.' },
    { id: 3, name: 'استشارة جلدية', specialty: 'الجلدية والتجميل', duration: '45 دقيقة', icon: 'icon-monitor', doctor: 3, description: 'استشارة متخصصة لعلاج مشاكل الجلد وتشخيص الأمراض الجلدية وإجراءات التجميل.', benefits: ['تشخيص دقيق لمشاكل الجلد', 'علاجات حديثة', 'إجراءات تجميلية آمنة'], suitable: 'جميع الأعمار، حالات حب الشباب، الصدفية، الأكزيما', prep: 'تجنب وضع المستحضرات على منطقة الفحص قبل الزيارة.' },
    { id: 4, name: 'فحص الأسنان', specialty: 'طب الأسنان', duration: '45 دقيقة', icon: 'icon-pulse', doctor: 1, description: 'فحص أسنان شامل مع تنظيف وإرشادات العناية بالفم والأسنان.', benefits: ['فحص شامل للأسنان', 'تنظيف احترافي', 'تخطيط علاجى'], suitable: 'جميع الأعمار', prep: 'لا يوجد تحضير خاص.' },
    { id: 5, name: 'استشارة نساء وولادة', specialty: 'النساء والولادة', duration: '45 دقيقة', icon: 'icon-heart-pulse', doctor: 4, description: 'استشارة متخصصة في صحة المرأة، متابعة الحمل، والفحوصات الدورية.', benefits: ['رعاية متكاملة للحمل', 'فحوصات دورية', 'استشارات ما قبل الزواج'], suitable: 'النساء في جميع المراحل العمرية', prep: 'تجنب العلاقات الزوجية قبل 24 ساعة من الموعد.' },
    { id: 6, name: 'فحص بالموجات فوق الصوتية', specialty: 'الأشعة', duration: '30 دقيقة', icon: 'icon-flask', doctor: null, description: 'فحص بالموجات فوق الصوتية (Ultrasound) لتشخيص حالات مختلفة بدقة عالية.', benefits: ['تصوير دقيق بدون إشعاع', 'نتائج فورية', 'آمن للحامل'], suitable: 'حسب indication الطبيب', prep: 'شرب 3-4 أكواب ماء قبل الفحص بساعة (للشكاوى البطنية).' },
    { id: 7, name: 'فحص طبي شامل', specialty: 'الطب العام', duration: '60 دقيقة', icon: 'icon-shield-check', doctor: 1, description: 'برنامج فحص شامل يتضمن تحاليل دم، قياس ضغط، فحص قلب ورئتين، وتقييم شامل للصحة.', benefits: ['تحاليل شاملة', 'تقييم صحة شامل', 'تقرير مفصل'], suitable: 'جميع الأعمار فوق 18 سنة، خاصة قبل بدء عمل جديد أو تأمين صحي', prep: 'الصيام 8-12 ساعة قبل الفحص.' },
    { id: 8, name: 'متابعة طبية', specialty: 'متعدد', duration: '20 دقيقة', icon: 'icon-calendar', doctor: null, description: 'زيارة متابعة لمتابعة حالتك الصحية بعد التشخيص وعلاجه.', benefits: ['متابعة منتظمة', 'تعديل الخطة العلاجية', 'استشارات سريعة'], suitable: 'مرضى الحالات المزمنة، ما بعد العمليات', prep: 'أحضر تقارير الزيارات السابقة.' }
  ];

  /* --- Render booking steps --- */
  var stepContainers = {
    1: document.getElementById('booking-step-1'),
    2: document.getElementById('booking-step-2'),
    3: document.getElementById('booking-step-3'),
    4: document.getElementById('booking-step-4'),
    5: document.getElementById('booking-step-5'),
    6: document.getElementById('booking-step-6')
  };

  doctors = [];
  services = [];
  var locations = [];
  var businessSlug = document.querySelector('meta[name="booking-business-slug"]')?.content || new URLSearchParams(window.location.search).get('business') || 'clinec-demo';
  var bookingErrors = {};
  var calendarAvailability = {};
  var calendarRequest = 0;
  var timeRequest = 0;

  var bookingState = {
    location: null,
    service: null,
    doctor: null,
    date: null,
    time: null,
    slot: null,
    timePeriod: null,
    calendarMonth: new Date(new Date().getFullYear(), new Date().getMonth(), 1),
    patient: { name: '', phone: '', email: '', notes: '' }
  };

  async function apiRequest(path, options) {
    options = options || {};
    var headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    headers.set('X-Business-Slug', businessSlug);
    if (options.body) headers.set('Content-Type', 'application/json');

    var response = await fetch('/api/v1' + path, Object.assign({}, options, { headers: headers }));
    var payload = await response.json().catch(function () { return {}; });
    if (!response.ok) {
      var validationError = payload.errors && Object.values(payload.errors).flat()[0];
      throw new Error(validationError || payload.message || 'تعذر الاتصال بالخادم.');
    }

    return payload.data;
  }

  function setStepError(step, message) {
    bookingErrors[step] = message;
    var container = stepContainers[step];
    if (!container) return;
    var error = container.querySelector('.booking-error');
    if (!error) {
      error = document.createElement('div');
      error.className = 'booking-error';
      error.setAttribute('role', 'alert');
      container.prepend(error);
    }
    error.textContent = message;
  }

  function clearStepError(step) {
    delete bookingErrors[step];
    var error = stepContainers[step] && stepContainers[step].querySelector('.booking-error');
    if (error) error.remove();
  }

  function renderSavedStepError(step) {
    return bookingErrors[step] ? '<div class="booking-error" role="alert">' + escapeHtml(bookingErrors[step]) + '</div>' : '';
  }

  async function loadServices() {
    if (!bookingState.location) {
      services = [];
      return;
    }
    var query = new URLSearchParams({ location_id: bookingState.location.id, per_page: '100' });
    var result = await apiRequest('/services?' + query.toString());
    services = result;
    window.services = services;
  }

  async function loadLocations() {
    try {
      locations = await apiRequest('/locations');
      if (locations.length === 0) throw new Error('لا توجد فروع نشطة متاحة للحجز.');
      bookingState.location = locations[0];
      await loadServices();
      renderStep1();
    } catch (error) {
      setStepError(1, error.message);
    }
  }

  async function loadStaff() {
    if (!bookingState.service || !bookingState.location) return [];
    var query = new URLSearchParams({
      service_id: bookingState.service.id,
      location_id: bookingState.location.id
    });
    doctors = await apiRequest('/staff?' + query.toString());
    window.doctors = doctors;
    return doctors;
  }

  /* Step 1: Choose Service */
  function renderStep1() {
    if (!stepContainers[1]) return;
    var html = renderSavedStepError(1) + '<div class="booking-step__body">';
    html += '<div class="booking-step__header">';
    html += '<h2>اختر الفرع والخدمة</h2>';
    html += '<p>اختر الفرع ثم الخدمة المناسبة لك.</p>';
    html += '</div>';
    html += '<div class="form-group booking-location">';
    html += '<label for="booking-location-select">الفرع</label>';
    html += '<select id="booking-location-select" class="form-input" ' + (locations.length ? '' : 'disabled') + '>';
    if (locations.length === 0) {
      html += '<option value="">جارٍ تحميل الفروع...</option>';
    } else {
      locations.forEach(function (location) {
        html += '<option value="' + escapeHtml(location.id) + '" ' + (bookingState.location && bookingState.location.id === location.id ? 'selected' : '') + '>' + escapeHtml(location.name) + '</option>';
      });
    }
    html += '</select></div>';
    html += '<div class="booking-service-grid">';
    services.forEach(function (svc) {
      var iconSvg = getIconSVG('icon-stethoscope');
      html += '<div class="booking-service-card' + (bookingState.service && bookingState.service.id === svc.id ? ' is-selected' : '') + '" data-service-id="' + svc.id + '">';
      html += '<div class="booking-service-card__icon">' + iconSvg + '</div>';
      html += '<h4>' + escapeHtml(svc.name) + '</h4>';
      html += '<p>' + escapeHtml(String(svc.duration_minutes)) + ' دقيقة</p>';
      html += '</div>';
    });
    if (locations.length && services.length === 0) html += '<p>لا توجد خدمات نشطة في هذا الفرع.</p>';
    html += '</div></div>';
    stepContainers[1].innerHTML = html;

    var locationSelect = stepContainers[1].querySelector('#booking-location-select');
    if (locationSelect) {
      locationSelect.addEventListener('change', async function () {
        bookingState.location = locations.find(function (location) { return location.id === this.value; }, this);
        bookingState.service = null;
        bookingState.doctor = null;
        bookingState.date = null;
        bookingState.time = null;
        bookingState.slot = null;
        calendarAvailability = {};
        clearStepError(1);
        try {
          await loadServices();
          renderStep1();
        } catch (error) {
          setStepError(1, error.message);
        }
      });
    }

    stepContainers[1].querySelectorAll('.booking-service-card').forEach(function (card) {
      card.addEventListener('click', function () {
        stepContainers[1].querySelectorAll('.booking-service-card').forEach(function (c) { c.classList.remove('is-selected'); });
        this.classList.add('is-selected');
        var id = parseInt(this.getAttribute('data-service-id'), 10);
        bookingState.service = services.find(function (s) { return s.id === this.getAttribute('data-service-id'); }, this);
        bookingState.doctor = null;
        bookingState.date = null;
        bookingState.time = null;
        bookingState.slot = null;
        calendarAvailability = {};
        clearStepError(1);
        renderStepIndicator();
      });
    });
  }

  /* Step 2: Choose Doctor */
  function renderStep2() {
    if (!stepContainers[2]) return;
    var html = renderSavedStepError(2) + '<div class="booking-step__body">';
    html += '<div class="booking-step__header">';
    html += '<h2>اختر الطبيب</h2>';
    html += '<p>اختر الطبيب الذي يناسبك من فريقنا الطبي.</p>';
    html += '</div>';
    html += '<div class="booking-doctor-list">';
    doctors.forEach(function (doc) {
      var isSelected = bookingState.doctor && bookingState.doctor.id === doc.id;
      html += '<div class="booking-doctor-card' + (isSelected ? ' is-selected' : '') + '" data-doctor-id="' + doc.id + '">';
      html += '<div class="booking-doctor-card__avatar" aria-hidden="true">' + escapeHtml(doc.name.slice(0, 1)) + '</div>';
      html += '<div class="booking-doctor-card__info">';
      html += '<h4>' + escapeHtml(doc.name) + '</h4>';
      html += '<p>متاح لهذه الخدمة في الفرع المختار</p>';
      html += '</div>';
      html += '</div>';
    });
    if (doctors.length === 0) html += '<p>لا يوجد طبيب متاح لهذه الخدمة في هذا الفرع.</p>';
    html += '</div></div>';
    stepContainers[2].innerHTML = html;

    stepContainers[2].querySelectorAll('.booking-doctor-card').forEach(function (card) {
      card.addEventListener('click', function () {
        stepContainers[2].querySelectorAll('.booking-doctor-card').forEach(function (c) { c.classList.remove('is-selected'); });
        this.classList.add('is-selected');
        var id = this.getAttribute('data-doctor-id');
        bookingState.doctor = doctors.find(function (d) { return d.id === id; });
        bookingState.date = null;
        bookingState.time = null;
        bookingState.slot = null;
        calendarAvailability = {};
        clearStepError(2);
        renderStepIndicator();
      });
    });
  }

  /* Step 3: Choose Date */
  function renderStep3() {
    if (!stepContainers[3]) return;
    var html = renderSavedStepError(3) + '<div class="booking-step__body">';
    html += '<div class="booking-step__header">';
    html += '<h2>اختر التاريخ</h2>';
    html += '<p>الأيام التي لا تتوفر فيها مواعيد تظهر باللون الأحمر.</p>';
    html += '</div>';
    html += '<div class="calendar-wrap"><div id="calendar-container"></div></div>';
    html += '</div>';
    stepContainers[3].innerHTML = html;

    updateCalendarMonth(bookingState.calendarMonth.getFullYear(), bookingState.calendarMonth.getMonth());
  }

  /* Step 4: Choose Time */
  function renderStep4() {
    if (!stepContainers[4]) return;
    var html = renderSavedStepError(4) + '<div class="booking-step__body">';
    html += '<div class="booking-step__header">';
    html += '<h2>اختر الوقت</h2>';
    html += '<p>الأوقات غير المتاحة تظهر باللون الأحمر ومشطوبة.</p>';
    html += '</div>';
    html += '<div class="time-slots" id="time-slots-container"><p>جارٍ تحميل الأوقات...</p></div>';
    html += '</div>';

    stepContainers[4].innerHTML = html;
    var requestId = ++timeRequest;
    var query = new URLSearchParams({
      service_id: bookingState.service.id,
      location_id: bookingState.location.id,
      staff_id: bookingState.doctor.id,
      date: bookingState.date
    });
    apiRequest('/availability?' + query.toString()).then(function (data) {
      if (requestId !== timeRequest) return;
      renderTimeOptions(data.slot_options || data.slots.map(function (slot) { return Object.assign({}, slot, { available: true }); }));
    }).catch(function (error) {
      if (requestId === timeRequest) setStepError(4, error.message);
    });
  }

  function renderTimeOptions(options) {
    var container = stepContainers[4].querySelector('#time-slots-container');
    if (!container) return;
    var periods = {
      morning: options.filter(function (option) { return getSlotPeriod(option.start) === 'morning'; }),
      evening: options.filter(function (option) { return getSlotPeriod(option.start) === 'evening'; })
    };
    var availablePeriods = ['morning', 'evening'].filter(function (period) {
      return periods[period].length > 0;
    });

    if (availablePeriods.length === 0) {
      container.innerHTML = '<p>لا توجد أوقات متاحة في هذا اليوم.</p>';
      return;
    }
    var activePeriod = availablePeriods.indexOf(bookingState.timePeriod) === -1
      ? availablePeriods[0]
      : bookingState.timePeriod;
    var labels = { morning: 'صباحًا', evening: 'مساءً' };
    var html = '<div class="time-tabs" role="tablist" aria-label="فترة الموعد">';
    availablePeriods.forEach(function (period) {
      var isActive = period === activePeriod;
      html += '<button type="button" class="time-tab' + (isActive ? ' is-active' : '') + '" role="tab" aria-selected="' + (isActive ? 'true' : 'false') + '" data-period="' + period + '">' + labels[period] + '</button>';
    });
    html += '</div><div class="time-slots" id="time-slots-list" role="tabpanel"></div>';
    container.innerHTML = html;

    var slotsContainer = container.querySelector('#time-slots-list');
    renderTimePeriod(slotsContainer, periods[activePeriod]);
    container.querySelectorAll('.time-tab').forEach(function (tab) {
      tab.addEventListener('click', function () {
        activePeriod = this.getAttribute('data-period');
        bookingState.timePeriod = activePeriod;
        container.querySelectorAll('.time-tab').forEach(function (periodTab) {
          var isActive = periodTab === tab;
          periodTab.classList.toggle('is-active', isActive);
          periodTab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        renderTimePeriod(slotsContainer, periods[activePeriod]);
      });
    });
  }

  function renderTimePeriod(container, options) {
    container.innerHTML = options.map(function (option) {
      var selected = bookingState.slot && bookingState.slot.start === option.start;
      var className = 'time-slot' + (option.available ? '' : ' is-unavailable') + (selected ? ' is-selected' : '');
      return '<button type="button" class="' + className + '" data-start="' + escapeHtml(option.start) + '" ' + (option.available ? '' : 'disabled title="غير متاح"') + '>' + escapeHtml(formatSlotTime(option.start)) + '</button>';
    }).join('');
    container.querySelectorAll('.time-slot:not(:disabled)').forEach(function (button) {
      button.addEventListener('click', function () {
        container.querySelectorAll('.time-slot').forEach(function (slot) { slot.classList.remove('is-selected'); });
        this.classList.add('is-selected');
        bookingState.slot = options.find(function (option) { return option.start === this.getAttribute('data-start'); }, this);
        bookingState.time = formatSlotTime(this.getAttribute('data-start'));
        clearStepError(4);
        renderStepIndicator();
      });
    });
  }

  function getSlotPeriod(value) {
    var formatOptions = { hour: '2-digit', hourCycle: 'h23' };
    if (bookingState.location && bookingState.location.timezone) {
      formatOptions.timeZone = bookingState.location.timezone;
    }
    var hour = Number(new Intl.DateTimeFormat('en', formatOptions).format(new Date(value)));

    return hour < 12 ? 'morning' : 'evening';
  }

  function formatSlotTime(value) {
    var options = { hour: 'numeric', minute: '2-digit' };
    if (bookingState.location && bookingState.location.timezone) options.timeZone = bookingState.location.timezone;
    return new Intl.DateTimeFormat('ar-SA', options).format(new Date(value));
  }

  function formatDate(value) {
    if (!value) return '—';
    return new Date(value + 'T12:00:00').toLocaleDateString('ar-SA', {
      weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
    });
  }

  function dateKey(year, month, day) {
    return year + '-' + String(month + 1).padStart(2, '0') + '-' + String(day).padStart(2, '0');
  }

  function renderCalendarGrid() {
    var container = document.getElementById('calendar-container');
    if (!container) return;
    var year = bookingState.calendarMonth.getFullYear();
    var month = bookingState.calendarMonth.getMonth();
    var firstDay = new Date(year, month, 1);
    var daysInMonth = new Date(year, month + 1, 0).getDate();
    var today = new Date();
    var todayKey = dateKey(today.getFullYear(), today.getMonth(), today.getDate());
    var monthNames = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
    var dayNames = ['أحد', 'إثنين', 'ثلاثاء', 'أربعاء', 'خميس', 'جمعة', 'سبت'];
    var maxAdvance = bookingState.service.max_advance_days || 365;
    var maxDate = new Date(today.getFullYear(), today.getMonth(), today.getDate() + maxAdvance);
    var canGoNext = firstDay <= new Date(maxDate.getFullYear(), maxDate.getMonth(), 1);
    var html = '<div class="cal-month-header">';
    html += '<button class="cal-nav" data-action="prev" aria-label="الشهر السابق" ' + (firstDay.getFullYear() === today.getFullYear() && month === today.getMonth() ? 'disabled' : '') + '>‹</button>';
    html += '<span class="cal-month-label">' + monthNames[month] + ' ' + year + '</span>';
    html += '<button class="cal-nav" data-action="next" aria-label="الشهر التالي" ' + (canGoNext ? '' : 'disabled') + '>›</button>';
    html += '</div><div class="cal-days-header">' + dayNames.map(function (day) { return '<div class="cal-day-name">' + day + '</div>'; }).join('') + '</div><div class="cal-grid">';
    for (var pad = 0; pad < firstDay.getDay(); pad++) html += '<div class="cal-day cal-day--empty"></div>';
    for (var day = 1; day <= daysInMonth; day++) {
      var key = dateKey(year, month, day);
      var past = key < todayKey;
      var hasStatus = Object.prototype.hasOwnProperty.call(calendarAvailability, key);
      var available = hasStatus && calendarAvailability[key];
      var unavailable = !past && hasStatus && !available;
      var pending = !past && !hasStatus;
      var selected = bookingState.date === key;
      var className = 'cal-day' + (unavailable ? ' cal-day--unavailable' : '') + (pending ? ' cal-day--pending' : '') + (selected ? ' cal-day--selected' : '');
      var disabled = past || pending || !available;
      html += '<button type="button" class="' + className + '" data-date="' + key + '" aria-label="' + day + ' ' + monthNames[month] + '" ' + (disabled ? 'disabled' : '') + '>' + day + '</button>';
    }
    html += '</div>';
    container.innerHTML = html;

    container.querySelectorAll('.cal-nav:not(:disabled)').forEach(function (button) {
      button.addEventListener('click', function () {
        var offset = this.getAttribute('data-action') === 'next' ? 1 : -1;
        updateCalendarMonth(year, month + offset);
      });
    });
    container.querySelectorAll('.cal-day:not(:disabled)').forEach(function (button) {
      button.addEventListener('click', function () {
        bookingState.date = this.getAttribute('data-date');
        bookingState.time = null;
        bookingState.slot = null;
        clearStepError(3);
        renderCalendarGrid();
        renderStepIndicator();
      });
    });
  }

  async function updateCalendarMonth(year, month) {
    bookingState.calendarMonth = new Date(year, month, 1);
    calendarAvailability = {};
    var requestId = ++calendarRequest;
    renderCalendarGrid();
    var from = dateKey(year, month, 1);
    var until = dateKey(year, month, new Date(year, month + 1, 0).getDate());
    var query = new URLSearchParams({
      service_id: bookingState.service.id,
      location_id: bookingState.location.id,
      staff_id: bookingState.doctor.id,
      from: from,
      until: until
    });
    try {
      var dates = await apiRequest('/availability/calendar?' + query.toString());
      if (requestId !== calendarRequest) return;
      dates.forEach(function (item) { calendarAvailability[item.date] = item.available; });
      clearStepError(3);
      renderCalendarGrid();
    } catch (error) {
      if (requestId === calendarRequest) setStepError(3, error.message);
    }
  }

  /* Step 5: Patient Info */
  function renderStep5() {
    if (!stepContainers[5]) return;
    var p = bookingState.patient;
    var html = '<div class="booking-step__body">';
    html += '<div class="booking-step__header">';
    html += '<h2>بيانات المريض</h2>';
    html += '<p>أدخل بياناتك الأساسية لنتمكن من تأكيد الموعد.</p>';
    html += '</div>';
    html += '<form id="patient-form" novalidate>';
    html += '<div class="form-group">';
    html += '<label for="patient-name">الاسم الكامل <span class="required">*</span></label>';
    html += '<input type="text" id="patient-name" class="form-input" value="' + escapeHtml(p.name) + '" placeholder="أدخل اسمك الكامل" required minlength="2">';
    html += '<span class="form-error">يرجى إدخال الاسم الكامل (حرفان على الأقل)</span>';
    html += '</div>';
    html += '<div class="form-group">';
    html += '<label for="patient-phone">رقم الجوال <span class="required">*</span></label>';
    html += '<input type="tel" id="patient-phone" class="form-input" value="' + escapeHtml(p.phone) + '" placeholder="05XXXXXXXX" required pattern="^05[0-9]{8}$">';
    html += '<span class="form-error">يرجى إدخال رقم جوال سعودي صحيح (يبدأ بـ 05)</span>';
    html += '</div>';
    html += '<div class="form-group">';
    html += '<label for="patient-email">البريد الإلكتروني <span class="required">*</span></label>';
    html += '<input type="email" id="patient-email" class="form-input" value="' + escapeHtml(p.email) + '" placeholder="example@email.com" required>';
    html += '<span class="form-error">يرجى إدخال بريد إلكتروني صحيح</span>';
    html += '</div>';
    html += '<div class="form-group">';
    html += '<label for="patient-notes">ملاحظات إضافية</label>';
    html += '<textarea id="patient-notes" class="form-input" maxlength="500" placeholder="أي ملاحظات تود إضافتها (اختياري)">' + escapeHtml(p.notes) + '</textarea>';
    html += '</div>';
    html += '</form></div>';
    stepContainers[5].innerHTML = html;

    /* Real-time validation */
    var fields = ['patient-name', 'patient-phone', 'patient-email'];
    fields.forEach(function (id) {
      var input = document.getElementById(id);
      if (!input) return;
      input.addEventListener('blur', function () { validateField(this); });
      input.addEventListener('input', function () {
        if (this.closest('.form-group').classList.contains('is-invalid')) {
          validateField(this);
        }
      });
    });
  }

  function validateField(input) {
    var group = input.closest('.form-group');
    var isValid = true;
    if (input.required && !input.value.trim()) {
      isValid = false;
    }
    if (input.pattern && input.value) {
      var re = new RegExp(input.pattern);
      if (!re.test(input.value)) isValid = false;
    }
    if (input.type === 'email' && input.value) {
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.value)) isValid = false;
    }
    if (input.id === 'patient-name' && input.value.trim().length < 2) isValid = false;
    group.classList.toggle('is-invalid', !isValid);
    return isValid;
  }

  function validateForm() {
    var valid = true;
    var inputs = document.querySelectorAll('#patient-form .form-input[required]');
    inputs.forEach(function (input) {
      if (!validateField(input)) valid = false;
    });
    return valid;
  }

  /* Step 6: Review */
  function renderStep6() {
    if (!stepContainers[6]) return;
    var s = bookingState;
    var dateStr = formatDate(s.date);
    var html = '<div class="booking-step__body">';
    html += '<div class="booking-step__header">';
    html += '<h2>مراجعة الحجز</h2>';
    html += '<p>راجع التفاصيل قبل تأكيد الموعد.</p>';
    html += '</div>';
    html += '<div class="review-table">';
    html += reviewRow('الخدمة', s.service ? s.service.name : '—', 1);
    html += reviewRow('الطبيب', s.doctor ? s.doctor.name : '—', 2);
    html += reviewRow('التاريخ', dateStr, 3);
    html += reviewRow('الوقت', s.time || '—', 4);
    html += reviewRow('الاسم', s.patient.name || '—', 5);
    html += reviewRow('الجوال', s.patient.phone || '—', 5);
    html += reviewRow('البريد', s.patient.email || '—', 5);
    html += reviewRow('الملاحظات', s.patient.notes || 'لا توجد', 5);
    html += '</div></div>';
    stepContainers[6].innerHTML = html;
  }

  function reviewRow(label, value, step) {
    return '<div class="review-row">' +
      '<span class="review-label">' + label + '</span>' +
      '<span class="review-value">' + escapeHtml(value) + '</span>' +
      '<button class="btn btn--sm btn-secondary edit-btn" data-step="' + step + '" type="button">تعديل</button>' +
      '</div>';
  }

  /* Step indicator */
  function renderStepIndicator() {
    var indicator = document.querySelector('.step-indicator');
    if (!indicator) return;
    var currentStep = bookingCurrentStep;
    var steps = [
      { label: 'الخدمة', num: 1 },
      { label: 'الطبيب', num: 2 },
      { label: 'التاريخ', num: 3 },
      { label: 'الوقت', num: 4 },
      { label: 'البيانات', num: 5 },
      { label: 'المراجعة', num: 6 }
    ];

    var html = '';
    steps.forEach(function (step, i) {
      var cls = 'step-indicator__step';
      if (step.num < currentStep) cls += ' is-completed';
      else if (step.num === currentStep) cls += ' is-current';
      if (step.num < currentStep) cls += ' is-clickable';

      html += '<div class="' + cls + '" data-step="' + step.num + '">';
      if (i > 0) html += '<div class="step-indicator__connector"></div>';
      html += '<div class="step-indicator__circle">';
      if (step.num < currentStep) {
        html += '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>';
      } else {
        html += step.num;
      }
      html += '</div>';
      html += '<span class="step-indicator__label">' + step.label + '</span>';
      html += '</div>';
    });
    indicator.innerHTML = html;

    indicator.querySelectorAll('.is-clickable').forEach(function (el) {
      el.addEventListener('click', function () {
        var step = parseInt(this.getAttribute('data-step'), 10);
        goToStep(step);
      });
    });
  }

  /* Navigation */
  var bookingCurrentStep = 1;
  var totalSteps = 6;

  function goToStep(step) {
    bookingCurrentStep = step;
    window.bookingCurrentStep = step;
    Object.keys(stepContainers).forEach(function (k) {
      if (stepContainers[k]) stepContainers[k].style.display = 'none';
    });
    if (stepContainers[step]) {
      stepContainers[step].style.display = 'block';
    }
    renderStepIndicator();
    if (step === 1) renderStep1();
    if (step === 2) renderStep2();
    if (step === 3) renderStep3();
    if (step === 4) renderStep4();
    if (step === 5) renderStep5();
    if (step === 6) renderStep6();
  }

  function collectPatientFormData() {
    var form = document.getElementById('patient-form');
    if (!form) return;

    var name = form.querySelector('#patient-name');
    var phone = form.querySelector('#patient-phone');
    var email = form.querySelector('#patient-email');
    var notes = form.querySelector('#patient-notes');

    bookingState.patient = {
      name: name ? name.value.trim() : bookingState.patient.name,
      phone: phone ? phone.value.trim() : bookingState.patient.phone,
      email: email ? email.value.trim() : bookingState.patient.email,
      notes: notes ? notes.value.trim() : bookingState.patient.notes
    };
  }

  async function nextStep() {
    clearStepError(bookingCurrentStep);

    if (bookingCurrentStep === 1) {
      if (!bookingState.location || !bookingState.service) {
        setStepError(1, 'اختر الفرع والخدمة للمتابعة.');
        return;
      }
      try {
        await loadStaff();
      } catch (error) {
        setStepError(1, error.message);
        return;
      }
      if (doctors.length === 0) {
        setStepError(1, 'لا يوجد طبيب متاح لهذه الخدمة في الفرع المختار.');
        return;
      }
    }

    if (bookingCurrentStep === 2 && !bookingState.doctor) {
      setStepError(2, 'اختر الطبيب للمتابعة.');
      return;
    }

    if (bookingCurrentStep === 3 && !bookingState.date) {
      setStepError(3, 'اختر يومًا تتوفر فيه مواعيد.');
      return;
    }

    if (bookingCurrentStep === 4 && !bookingState.slot) {
      setStepError(4, 'اختر وقتًا متاحًا للمتابعة.');
      return;
    }

    if (bookingCurrentStep === 5) {
      if (!validateForm()) return;
      collectPatientFormData();
    }

    if (bookingCurrentStep < totalSteps) {
      bookingCurrentStep++;
      goToStep(bookingCurrentStep);
      if (bookingCurrentStep === totalSteps) renderStep6();
    }
  }

  function prevStep() {
    if (bookingCurrentStep > 1) {
      bookingCurrentStep--;
      goToStep(bookingCurrentStep);
    }
  }

  async function confirmBooking() {
    var confirmation = document.getElementById('booking-confirmation');
    if (!confirmation) return false;

    var key = window.crypto && typeof window.crypto.randomUUID === 'function'
      ? window.crypto.randomUUID()
      : 'booking-' + Date.now() + '-' + Math.random().toString(16).slice(2);

    try {
      var booking = await apiRequest('/bookings', {
        method: 'POST',
        headers: { 'Idempotency-Key': key },
        body: JSON.stringify({
          service_id: bookingState.service.id,
          staff_id: bookingState.doctor.id,
          location_id: bookingState.location.id,
          start: bookingState.slot.start,
          customer: {
            name: bookingState.patient.name,
            phone: bookingState.patient.phone,
            email: bookingState.patient.email || null
          },
          notes: bookingState.patient.notes || null
        })
      });
      bookingState.booking = booking;
    } catch (error) {
      setStepError(6, error.message);
      return false;
    }

    var html = '<div class="confirmation-content">';
    html += '<div class="confirmation-icon">';
    html += '<svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="var(--color-success)" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><polyline points="16 8 10 16 7 13"/></svg>';
    html += '</div>';
    html += '<h2>تم إنشاء الحجز بنجاح</h2>';
    html += '<p class="confirmation-ref">رقم الحجز: <strong>' + escapeHtml(booking.reference) + '</strong></p>';
    html += '<div class="review-table">';
    html += reviewRow('الخدمة', bookingState.service.name, 0);
    html += reviewRow('الطبيب', bookingState.doctor.name, 0);
    html += reviewRow('الفرع', bookingState.location.name, 0);
    html += reviewRow('التاريخ', formatDate(bookingState.date), 0);
    html += reviewRow('الوقت', bookingState.time, 0);
    html += reviewRow('الحالة', booking.status, 0);
    html += '</div>';
    html += '<div class="confirmation-actions">';
    html += '<a href="/booking/" class="btn btn-primary btn--lg">حجز موعد آخر</a>';
    html += '</div>';
    html += '</div>';
    confirmation.innerHTML = html;
    return true;
  }

  /* Icon SVG helper */
  function getIconSVG(iconName) {
    var paths = {
      'icon-stethoscope': '<path d="M6 18V8a6 6 0 0 1 12 0v10"/><circle cx="12" cy="18" r="2"/><path d="M6 12h.01M18 12h.01"/>',
      'icon-child': '<circle cx="12" cy="5" r="3"/><path d="M12 8v4m0 4 2 4H10l2-4"/><path d="M7 16h10"/>',
      'icon-monitor': '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
      'icon-pulse': '<path d="M2 12h4l2-6 4 12 2-6h4"/>',
      'icon-heart-pulse': '<path d="M19 14c1.657 0 3-2.239 3-5s-1.343-5-3-5-3 2.239-3 5c0 1.5.5 2.5 1 3.5L12 21 6 17.5c.5-1 1-2 1-3.5C7 6.239 5.657 4 4 4S1 6.239 1 9s1.343 5 3 5"/><path d="M10 9h4M12 7v4"/>',
      'icon-flask': '<path d="M9 3h6M10 3v7.4a2 2 0 0 1-.5 1.3L5 19a2 2 0 0 0 1.5 3h9a2 2 0 0 0 1.5-3l-4.5-7.3A2 2 0 0 1 14 10.4V3"/>',
      'icon-shield-check': '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/>',
      'icon-calendar': '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>'
    };
    var d = paths[iconName] || paths['icon-stethoscope'];
    return '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">' + d + '</svg>';
  }

  function escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  /* Expose globals */
  window.bookingState = bookingState;
  window.bookingCurrentStep = 1;
  window.goToStep = goToStep;
  window.nextStep = nextStep;
  window.prevStep = prevStep;
  window.confirmBooking = confirmBooking;
  window.renderStep1 = renderStep1;
  window.renderStep2 = renderStep2;
  window.renderStep3 = renderStep3;
  window.renderStep4 = renderStep4;
  window.renderStep5 = renderStep5;
  window.renderStep6 = renderStep6;
  window.renderStepIndicator = renderStepIndicator;
  window.doctors = doctors;
  window.services = services;

  /* Initialize on DOM ready */
  document.addEventListener('DOMContentLoaded', function () {
    if (document.getElementById('booking-app')) {
      renderStep1();
      renderStepIndicator();
      Object.keys(stepContainers).forEach(function (k) {
        if (stepContainers[k] && k != 1) stepContainers[k].style.display = 'none';
      });
      loadLocations();
    }
  });

})();
