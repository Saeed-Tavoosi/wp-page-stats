(function () {
  'use strict';
  var day = 86400000;
  var formatter;
  try { formatter = new Intl.DateTimeFormat('en-US-u-ca-persian', {year:'numeric',month:'2-digit',day:'2-digit',timeZone:'UTC'}); } catch (_) {}
  function pad(n) { return String(n).padStart(2, '0'); }
  function parts(date) {
    var p = {};
    formatter.formatToParts(date).forEach(function (v) { p[v.type] = v.value; });
    return [Number(p.year),Number(p.month),Number(p.day)];
  }
  function normalize(text) {
    return text.replace(/[۰-۹]/g, function (n) { return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(n)); })
      .replace(/[٠-٩]/g, function (n) { return String('٠١٢٣٤٥٦٧٨٩'.indexOf(n)); }).trim();
  }
  function fromGregorian(iso) {
    if (!formatter || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) return '';
    var p = parts(new Date(iso + 'T12:00:00Z'));
    return p[0] + '/' + pad(p[1]) + '/' + pad(p[2]);
  }
  function toGregorian(text) {
    if (!formatter) return '';
    var m = /^(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})$/.exec(normalize(text));
    if (!m) return '';
    var y=Number(m[1]), month=Number(m[2]), d=Number(m[3]);
    if (y<1200 || y>1600 || month<1 || month>12 || d<1 || d>31) return '';
    var target=y*10000+month*100+d, gy=y+621;
    var low=Math.floor(Date.UTC(gy,2,1)/day), high=Math.floor(Date.UTC(gy+1,2,31)/day);
    while (low<=high) {
      var mid=Math.floor((low+high)/2), date=new Date(mid*day+43200000), p=parts(date);
      var value=p[0]*10000+p[1]*100+p[2];
      if (value===target) return date.toISOString().slice(0,10);
      if (value<target) low=mid+1; else high=mid-1;
    }
    return '';
  }
  window.WPSCalendar={fromGregorian:fromGregorian,toGregorian:toGregorian};
  var picker=document.getElementById('wps-calendar');
  if (!picker) return;
  if (!formatter) { picker.querySelector('[value="persian"]').disabled=true; return; }
  var mode='gregorian';
  try { if (localStorage.getItem('wps_calendar')==='persian') mode='persian'; } catch (_) {}
  var fields=Array.from(document.querySelectorAll('[data-wps-date-input]'));
  function sync(input) {
    var hidden=input.form.elements[input.dataset.wpsDateInput];
    var iso=mode==='persian' ? toGregorian(input.value) : gregorian(input.value);
    input.setCustomValidity(iso ? '' : (mode==='persian' ? 'تاریخ معتبر وارد کنید. نمونه: ۱۴۰۵/۰۸/۲۶' : 'تاریخ معتبر وارد کنید. نمونه: ۲۰۲۶/۱۱/۱۸'));
    if (iso) hidden.value=iso;
    return !!iso;
  }
  function render() {
    fields.forEach(function (input) {
      var iso=input.form.elements[input.dataset.wpsDateInput].value;
      input.type='text';
      input.placeholder=mode==='persian' ? '۱۴۰۵/۰۸/۲۶' : '۲۰۲۶/۱۱/۱۸';
      input.value=mode==='persian' ? fromGregorian(iso) : iso;
      input.dir='ltr'; input.setCustomValidity('');
    });
    document.querySelectorAll('[data-wps-date]').forEach(function (el) {
      el.textContent=mode==='persian' ? fromGregorian(el.dataset.wpsDate) : el.dataset.wpsDate;
    });
    picker.value=mode;
  }
  function gregorian(text) {
    const match = /^(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})$/.exec(normalize(text));
    if (!match || Number(match[1]) < 1000 || Number(match[1]) > 9998) return '';
    const iso = match[1] + '-' + pad(Number(match[2])) + '-' + pad(Number(match[3]));
    const date = new Date(iso + 'T12:00:00Z');
    return Number.isFinite(date.getTime()) && date.toISOString().slice(0, 10) === iso ? iso : '';
  }

  const months = {
    persian: ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'],
    gregorian: ['ژانویه','فوریه','مارس','آوریل','مه','ژوئن','ژوئیه','اوت','سپتامبر','اکتبر','نوامبر','دسامبر']
  };
  const numbers = new Intl.NumberFormat('fa-IR', {useGrouping:false});
  const today = picker.dataset.today;
  let active;
  function close(focus) {
    if (!active) return;
    const {panel, toggle, input} = active;
    panel.remove();
    toggle.setAttribute('aria-expanded','false');
    toggle.removeAttribute('aria-controls');
    active = null;
    if (focus) input.focus({preventScroll:true});
  }
  function monthStart(year, month) {
    return mode === 'persian' ? toGregorian(year + '/' + month + '/1') : gregorian(year + '-' + month + '-1');
  }
  function labelParts(iso) {
    return (mode === 'persian' ? fromGregorian(iso) : iso).split(/[\/-]/).map(Number);
  }
  function open(input, focusDay) {
    if (active && active.input === input) return;
    close(false);
    const wrapper = input.closest('.wps-date-field');
    const toggle = wrapper.querySelector('.wps-date-toggle');
    sync(input);
    const selected = input.form.elements[input.dataset.wpsDateInput].value;
    const parts = labelParts(selected);
    const [currentYear] = labelParts(today);
    const [initialYear, initialMonth] = monthStart(parts[0], parts[1]) ? parts : labelParts(today);
    const min = mode === 'persian' ? 1200 : Math.max(1000, Math.min(currentYear - 100, initialYear));
    const max = mode === 'persian' ? 1600 : Math.min(9998, Math.max(currentYear + 30, initialYear));
    let year = initialYear, month = initialMonth;
    const panel = document.createElement('div');
    panel.className = 'wps-date-popover';
    panel.style.fontFamily = getComputedStyle(input).fontFamily;
    panel.id = input.id + '-picker';
    panel.setAttribute('role','dialog');
    panel.setAttribute('aria-label','تقویم ' + wrapper.querySelector('label').textContent);
    panel.innerHTML = '<div class="wps-date-nav"><button type="button" data-prev aria-label="ماه قبل"></button><label>ماه<select></select></label><label>سال<select></select></label><button type="button" data-next aria-label="ماه بعد"></button></div><div class="wps-date-days"></div><button type="button" class="wps-date-today">امروز</button>';
    const [monthSelect, yearSelect] = panel.querySelectorAll('select');
    const previous = panel.querySelector('[data-prev]');
    const next = panel.querySelector('[data-next]');
    const grid = panel.querySelector('.wps-date-days');
    months[mode].forEach((name, index) => monthSelect.add(new Option(name, index + 1)));
    for (let value = min; value <= max; value++) {
      yearSelect.add(new Option(numbers.format(value), value));
    }
    monthSelect.value = month;
    yearSelect.value = year;
    function selectDate(iso) {
      input.value = mode === 'persian' ? fromGregorian(iso) : iso;
      input.dispatchEvent(new Event('input', {bubbles:true}));
      close(true);
    }
    function draw() {
      grid.replaceChildren();
      previous.disabled = year === min && month === 1;
      next.disabled = year === max && month === 12;
      const first = new Date(monthStart(year, month) + 'T12:00:00Z');
      const weekday = (first.getUTCDay() + 1) % 7;
      ['ش','ی','د','س','چ','پ','ج'].forEach(name => {
        const heading = document.createElement('span');
        heading.textContent = name;
        grid.appendChild(heading);
      });
      for (let i = 0; i < weekday; i++) grid.appendChild(document.createElement('span'));
      for (let i = 0; i < 31; i++) {
        const iso = new Date(first.getTime() + i * day).toISOString().slice(0, 10);
        if (labelParts(iso)[1] !== month) break;
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = numbers.format(i + 1);
        button.dataset.date = iso;
        button.setAttribute('aria-label', numbers.format(i + 1) + ' ' + months[mode][month - 1] + ' ' + numbers.format(year));
        button.setAttribute('aria-pressed', String(iso === selected));
        if (iso === today) button.setAttribute('aria-current','date');
        button.addEventListener('click', () => selectDate(iso));
        grid.appendChild(button);
      }
    }
    function move(step) {
      month += step;
      if (month === 0) { year--; month = 12; }
      if (month === 13) { year++; month = 1; }
      monthSelect.value = month;
      yearSelect.value = year;
      monthSelect.dispatchEvent(new Event('change'));
      yearSelect.dispatchEvent(new Event('change'));
    }
    monthSelect.addEventListener('change', () => { month = Number(monthSelect.value); draw(); });
    yearSelect.addEventListener('change', () => { year = Number(yearSelect.value); draw(); });
    previous.addEventListener('click', () => move(-1));
    next.addEventListener('click', () => move(1));
    panel.querySelector('.wps-date-today').addEventListener('click', () => selectDate(today));
    active = {panel, toggle, input};
    draw();
    wrapper.appendChild(panel);
    toggle.setAttribute('aria-expanded','true');
    toggle.setAttribute('aria-controls',panel.id);
    if (focusDay) (grid.querySelector('[aria-pressed=true]') || grid.querySelector('button')).focus();
  }
  fields.forEach(function (input) {
    const toggle = input.closest('.wps-date-field').querySelector('.wps-date-toggle');
    toggle.hidden = false;
    input.addEventListener('input', () => { sync(input); if (active) close(false); });
    input.addEventListener('click', () => open(input, false));
    input.addEventListener('keydown', event => {
      if (event.key === 'ArrowDown') { event.preventDefault(); close(false); open(input, true); }
    });
    toggle.addEventListener('click', () => {
      if (active && active.input === input) close(true);
      else open(input, true);
    });
  });
  document.addEventListener('pointerdown', event => {
    if (active && !active.input.closest('.wps-date-field').contains(event.target) && !event.target.closest('.wps-options')) close(false);
  });
  document.addEventListener('focusin', event => {
    if (active && !active.input.closest('.wps-date-field').contains(event.target) && !event.target.closest('.wps-options')) close(false);
  });
  document.addEventListener('keydown', event => {
    if (active && event.key === 'Escape' && !event.target.closest('.wps-options')) { event.preventDefault(); close(true); }
  });
  picker.addEventListener('change', function () {
    close(false);
    if (!fields.every(sync)) { picker.value=mode; fields.filter(function (i) { return !i.checkValidity(); })[0].reportValidity(); return; }
    mode=picker.value;
    try { localStorage.setItem('wps_calendar',mode); } catch (_) {}
    render();
  });
  picker.form.addEventListener('submit',function (e) {
    if (!fields.every(sync)) { e.preventDefault(); fields.filter(function (i) { return !i.checkValidity(); })[0].reportValidity(); }
  });
  render();
}());
