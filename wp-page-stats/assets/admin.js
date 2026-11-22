(function () {
  'use strict';
  var goals = document.getElementById('wps-goals'), addGoal = document.getElementById('wps-add-goal');
  function reindexGoals() {
    Array.from(goals.children).forEach(function (row, i) {
      row.querySelectorAll('[data-goal]').forEach(function (el) { el.name='goals['+i+']['+el.dataset.goal+']'; });
    });
  }
  if (goals && addGoal) {
    addGoal.addEventListener('click', function () {
      if (goals.children.length >= 20) return;
      var row = goals.firstElementChild.cloneNode(true);
      row.querySelectorAll('input').forEach(function (el) { el.value=''; });
      row.querySelector('select').value='destination'; row.querySelector('.wps-goal-id').textContent='';
      goals.appendChild(row); reindexGoals(); row.querySelector('[data-goal="label"]').focus();
    });
    goals.addEventListener('click', function (e) {
      if (!e.target.classList.contains('wps-remove-goal')) return;
      if (goals.children.length > 1) e.target.closest('.wps-goal-row').remove();
      else goals.firstElementChild.querySelectorAll('input').forEach(function (el) { el.value=''; });
      reindexGoals();
    });
  }
  var pageResults = document.getElementById('wps-report-page-results');
  if (pageResults && window.fetch && window.AbortController) {
    var pageHeading = document.getElementById('wps-report-pages-title');
    var pageStatus = document.getElementById('wps-report-pages-status');
    var pageRequest;
    var currentPage = new URL(location.href).searchParams.get('report_page') || '1';

    async function loadReportPage(url, navigate) {
      if (pageRequest) pageRequest.abort();
      var request = new AbortController();
      pageRequest = request;
      pageResults.setAttribute('aria-busy', 'true');
      pageStatus.textContent = 'در حال دریافت فهرست…';
      try {
        var endpoint = new URL(window.WPSAdmin.ajax, location.href);
        url.searchParams.forEach(function (value, key) { endpoint.searchParams.set(key, value); });
        endpoint.searchParams.set('action', 'wps_report_pages');
        endpoint.searchParams.set('_ajax_nonce', window.WPSAdmin.pagesNonce);
        var response = await fetch(endpoint, {credentials: 'same-origin', signal: request.signal});
        if (!response.ok) throw new Error('Report request failed');
        var result = await response.json();
        if (pageRequest !== request) return;
        if (!result.success || typeof result.data.html !== 'string') throw new Error('Invalid report');
        currentPage = String(result.data.page);
        pageResults.innerHTML = result.data.html;
        pageStatus.textContent = 'صفحهٔ ' + new Intl.NumberFormat('fa-IR').format(result.data.page) + ' نمایش داده شد.';
        if (navigate) {
          url.searchParams.set('report_page', currentPage);
          history.pushState(null, '', url);
          pageHeading.focus({preventScroll: true});
          pageHeading.closest('section').scrollIntoView({block: 'start'});
        }
      } catch (_) {
        if (pageRequest !== request) return;
        pageStatus.textContent = 'دریافت فهرست انجام نشد. ';
        var fallback = document.createElement('a');
        fallback.href = url.href;
        fallback.textContent = 'باز کردن صفحهٔ گزارش';
        pageStatus.appendChild(fallback);
      } finally {
        if (pageRequest === request) {
          pageRequest = null;
          pageResults.removeAttribute('aria-busy');
        }
      }
    }

    pageResults.addEventListener('click', function (event) {
      var link = event.target.closest('a[data-wps-report-page]');
      if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      loadReportPage(new URL(link.href), true);
    });
    window.addEventListener('popstate', function () {
      var url = new URL(location.href);
      if (pageRequest || (url.searchParams.get('report_page') || '1') !== currentPage) {
        loadReportPage(url, false);
      }
    });
  }
  var input = document.getElementById('wps-page-search');
  if (!input) return;
  var output = document.getElementById('wps-page-results');
  var status = document.getElementById('wps-page-status');
  var spinner = input.parentElement.querySelector('.wps-search-spinner');
  var id = document.getElementById('wps-post-id'), timer, controller;
  function loading(on) {
    spinner.hidden = !on;
    input.classList.toggle('wps-searching', on);
    input.setAttribute('aria-busy', String(on));
    status.textContent = on ? 'در حال جست‌وجو…' : '';
  }
  function resetSearch() {
    clearTimeout(timer);
    if (controller) controller.abort();
    controller = null;
    loading(false);
    output.replaceChildren();
  }
  function message(text) {
    var item = document.createElement('div');
    item.className = 'wps-search-list wps-search-message';
    item.textContent = text;
    output.replaceChildren(item);
  }
  input.addEventListener('input', function () {
    resetSearch();
    var q = input.value.trim();
    var numeric = /^\d+$/.test(q);
    id.value = numeric ? q : '0';
    if (numeric || !q) {
      var path = input.form.querySelector('input[name="page_key"]');
      if (path) path.remove();
    }
    if (q.length < 2 || numeric) return;
    loading(true);
    timer = setTimeout(function () {
      var request = new AbortController();
      controller = request;
      var params = new URLSearchParams({action: 'wps_find_page', nonce: window.WPSAdmin.nonce, q: q});
      fetch(window.WPSAdmin.ajax + '?' + params, {credentials: 'same-origin', signal: request.signal})
        .then(function (r) { return r.json(); }).then(function (r) {
          if (controller !== request) return;
          if (!r.success) throw new Error('Page search failed');
          if (!r.data.length) { message('صفحه‌ای پیدا نشد.'); return; }
          var list = document.createElement('span'); list.className = 'wps-search-list';
          r.data.forEach(function (p) {
            var button = document.createElement('button'); button.type = 'button';
            button.textContent = p.title + ' · #' + p.id;
            button.addEventListener('click', function () {
              id.value = String(p.id); input.value = p.title; resetSearch(); input.focus();
              var path = input.form.querySelector('input[name="page_key"]'); if (path) path.remove();
            }); list.appendChild(button);
          });
          output.replaceChildren(list);
        }).catch(function () {
          if (controller === request) message('جست‌وجو انجام نشد. دوباره امتحان کنید.');
        }).finally(function () {
          if (controller === request) { controller = null; loading(false); }
        });
    }, 250);
  });
  id.addEventListener('input', function () { input.value = ''; var path = input.form.querySelector('input[name="page_key"]'); if (path) path.remove(); });
  document.addEventListener('click', function (e) { if (!input.parentElement.contains(e.target)) resetSearch(); });
  input.parentElement.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { e.preventDefault(); resetSearch(); input.focus(); }
    if (e.key === 'ArrowDown' && e.target === input && output.querySelector('button')) {
      e.preventDefault(); output.querySelector('button').focus();
    }
  });
}());
