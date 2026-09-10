(function () {
  var themeNames = ['daylight', 'night', 'forest', 'twilight', 'china-red', 'glazed-yellow', 'cloud-gray'];
  function applyTheme(theme) {
    theme = themeNames.indexOf(theme) >= 0 ? theme : 'daylight';
    document.body.classList.remove('theme-night', 'theme-forest', 'theme-twilight', 'theme-china-red', 'theme-glazed-yellow', 'theme-cloud-gray');
    if (theme !== 'daylight') { document.body.classList.add('theme-' + theme); }
    var switcher = document.querySelector('[data-theme-switch]');
    if (switcher) { switcher.value = theme; }
    try { window.localStorage.setItem('site-navigator-theme', theme); document.cookie = 'site_navigator_theme=' + encodeURIComponent(theme) + '; path=/; max-age=31536000; SameSite=Lax'; } catch (error) {}
  }
  var savedTheme = window.siteNavigatorDefaultTheme || 'daylight';
  try { savedTheme = window.localStorage.getItem('site-navigator-theme') || savedTheme; } catch (error) {}
  applyTheme(savedTheme);
  var themeSwitch = document.querySelector('[data-theme-switch]');
  if (themeSwitch) {
    themeSwitch.addEventListener('click', function (event) { event.preventDefault(); event.stopPropagation(); });
    themeSwitch.addEventListener('pointerdown', function (event) { event.stopPropagation(); });
    themeSwitch.addEventListener('change', function (event) { event.stopPropagation(); applyTheme(themeSwitch.value); });
  }
  function placeMobileThemePicker() {
    var actions = document.querySelector('.topbar .header-actions');
    var brand = document.querySelector('.sidebar .brand');
    if (!actions || !brand) { return; }
    if (window.matchMedia('(max-width: 600px)').matches) {
      if (actions.parentNode !== brand) { brand.appendChild(actions); }
    } else {
      var topbar = document.querySelector('.topbar');
      if (topbar && actions.parentNode !== topbar) { topbar.appendChild(actions); }
    }
  }
  placeMobileThemePicker();
  window.addEventListener('resize', placeMobileThemePicker);

  function openModal(id) {
    var modal = document.getElementById(id);
    if (modal) {
      modal.classList.add('open');
      var firstInput = modal.querySelector('input:not([type=hidden]), textarea, select');
      if (firstInput) { window.setTimeout(function () { firstInput.focus(); }, 30); }
    }
  }

  function closeModal(id) {
    var modal = document.getElementById(id);
    if (modal) { modal.classList.remove('open'); }
  }

  function dismissToast(toast) {
    if (!toast || toast.classList.contains('is-closing')) { return; }
    toast.classList.add('is-closing');
    window.setTimeout(function () {
      if (toast.parentNode) { toast.parentNode.removeChild(toast); }
    }, 210);
  }

  Array.prototype.forEach.call(document.querySelectorAll('[data-toast]'), function (toast) {
    var duration = toast.classList.contains('toast-error') ? 8500 : 5000;
    var timer = null;
    function startTimer() {
      window.clearTimeout(timer);
      timer = window.setTimeout(function () { dismissToast(toast); }, duration);
    }
    function pauseTimer() { window.clearTimeout(timer); }
    var closeButton = toast.querySelector('[data-close-toast]');
    if (closeButton) { closeButton.addEventListener('click', function () { dismissToast(toast); }); }
    toast.addEventListener('mouseenter', pauseTimer);
    toast.addEventListener('mouseleave', startTimer);
    toast.addEventListener('focusin', pauseTimer);
    toast.addEventListener('focusout', startTimer);
    startTimer();
  });

  Array.prototype.forEach.call(document.querySelectorAll('[data-open-modal]'), function (button) {
    button.addEventListener('click', function () { openModal(button.getAttribute('data-open-modal')); });
  });
  Array.prototype.forEach.call(document.querySelectorAll('[data-close-modal]'), function (button) {
    button.addEventListener('click', function () { closeModal(button.getAttribute('data-close-modal')); });
  });
  Array.prototype.forEach.call(document.querySelectorAll('.modal-backdrop'), function (modal) {
    modal.addEventListener('click', function (event) { if (event.target === modal) { closeModal(modal.id); } });
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      var visibleToast = document.querySelector('[data-toast]:not(.is-closing)');
      if (visibleToast) {
        dismissToast(visibleToast);
        return;
      }
      Array.prototype.forEach.call(document.querySelectorAll('.modal-backdrop.open'), function (modal) { closeModal(modal.id); });
    }
  });

  function addHttpsPreferenceControl(form, controlId, defaultWww) {
    if (!form || form.elements.prefer_https || !form.elements.add_www) { return; }
    var wwwControl = form.elements.add_www;
    if (defaultWww) { wwwControl.checked = true; }
    var wwwLabel = wwwControl.parentNode;
    if (!wwwLabel || !wwwLabel.parentNode) { return; }
    var hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'prefer_https';
    hidden.value = '0';
    var label = document.createElement('label');
    label.className = wwwLabel.className || '';
    var checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.name = 'prefer_https';
    checkbox.id = controlId;
    checkbox.value = '1';
    checkbox.checked = true;
    label.appendChild(checkbox);
    label.appendChild(document.createTextNode(' 优先 HTTPS'));
    var optionsRow = wwwLabel.parentNode;
    if (!optionsRow.classList.contains('checkbox-options')) {
      optionsRow = document.createElement('div');
      optionsRow.className = 'domain-options';
      wwwLabel.parentNode.insertBefore(optionsRow, wwwLabel);
      optionsRow.appendChild(wwwLabel);
    }
    if (optionsRow.classList.contains('checkbox-options')) {
      optionsRow.insertBefore(hidden, wwwLabel.nextSibling);
      optionsRow.insertBefore(label, hidden.nextSibling);
    } else {
      optionsRow.appendChild(hidden);
      optionsRow.appendChild(label);
    }
  }

  function getHttpsPreferenceCheckbox(form) {
    return form ? form.querySelector('input[type="checkbox"][name="prefer_https"]') : null;
  }

  addHttpsPreferenceControl(document.getElementById('addSiteForm'), 'add_prefer_https', true);
  addHttpsPreferenceControl(document.getElementById('batchImportForm'), 'batch_prefer_https', true);
  addHttpsPreferenceControl(document.getElementById('editSiteForm'), 'edit_prefer_https', false);

  function setupWebsiteListSearch() {
    var filterForm = document.querySelector('#websites .page-size-form');
    if (!filterForm || filterForm.querySelector('[data-website-keyword-search]')) { return; }
    var query = new URLSearchParams(window.location.search).get('keyword') || '';
    var searchLabel = document.createElement('label');
    searchLabel.className = 'website-keyword-search';
    searchLabel.setAttribute('data-website-keyword-search', '');
    searchLabel.appendChild(document.createTextNode('搜索网站'));
    var searchInput = document.createElement('input');
    searchInput.type = 'search';
    searchInput.name = 'keyword';
    searchInput.value = query;
    searchInput.maxLength = 120;
    searchInput.placeholder = '标题、主/备域名、介绍';
    searchInput.setAttribute('aria-label', '搜索网站标题、域名或介绍');
    searchLabel.appendChild(searchInput);
    filterForm.insertBefore(searchLabel, filterForm.firstChild);
    var searchTimer = null;
    var submittedKeyword = query;
    var isComposingKeyword = false;
    function submitKeywordSearch() {
      var nextKeyword = searchInput.value;
      if (nextKeyword === submittedKeyword) { return; }
      submittedKeyword = nextKeyword;
      var pageInput = filterForm.querySelector('input[name="page"]');
      if (pageInput) { pageInput.value = '1'; }
      searchInput.setAttribute('aria-busy', 'true');
      if (typeof filterForm.requestSubmit === 'function') {
        filterForm.requestSubmit();
      } else {
        filterForm.submit();
      }
    }
    searchInput.addEventListener('input', function () {
      if (isComposingKeyword) { return; }
      if (searchTimer) { window.clearTimeout(searchTimer); }
      searchTimer = window.setTimeout(submitKeywordSearch, 380);
    });
    searchInput.addEventListener('compositionstart', function () {
      isComposingKeyword = true;
      if (searchTimer) { window.clearTimeout(searchTimer); }
    });
    searchInput.addEventListener('compositionend', function () {
      isComposingKeyword = false;
      if (searchTimer) { window.clearTimeout(searchTimer); }
      searchTimer = window.setTimeout(submitKeywordSearch, 380);
    });
    searchInput.addEventListener('search', function () {
      if (searchTimer) { window.clearTimeout(searchTimer); }
      submitKeywordSearch();
    });

    Array.prototype.forEach.call(document.querySelectorAll('#websites form[method="post"], #editSiteForm, #addSiteForm, #batchImportForm, #csvImportModal form'), function (form) {
      if (!form || form.querySelector('[name="_return_keyword"]')) { return; }
      var hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = '_return_keyword';
      hidden.value = query;
      form.appendChild(hidden);
    });
  }

  setupWebsiteListSearch();

  var addSitePrimaryField = document.querySelector('#addSiteForm .form-field');
  var addSitePrimaryHint = addSitePrimaryField ? addSitePrimaryField.querySelector('.form-hint') : null;
  if (addSitePrimaryHint) {
    addSitePrimaryHint.textContent = '可直接填写域名；服务器可读取时会自动回填。若读取失败，直接补充标题和介绍后保存即可。';
  }

  var batchImportIntro = document.querySelector('#batchImportModal .batch-import-intro');
  if (batchImportIntro) {
    Array.prototype.forEach.call(batchImportIntro.childNodes, function (node) {
      if (node.nodeType === 3) { node.nodeValue = node.nodeValue.replace('自动 HTTPS', '优先 HTTPS'); }
    });
  }

  Array.prototype.forEach.call(document.querySelectorAll('[data-edit-site]'), function (button) {
    button.addEventListener('click', function () {
      var site = JSON.parse(button.getAttribute('data-edit-site'));
      document.getElementById('edit_id').value = site.id;
      if (document.getElementById('edit_new_id')) { document.getElementById('edit_new_id').value = site.id; }
      document.getElementById('edit_primary_url').value = site.primary_url || '';
      document.getElementById('edit_title').value = site.title || '';
      document.getElementById('edit_category_id').value = site.category_id || '';
      document.getElementById('edit_description').value = site.description || '';
      document.getElementById('edit_backup_url').value = site.backup_url || '';
      if (document.getElementById('edit_created_at')) { document.getElementById('edit_created_at').value = String(site.created_at || '').slice(0, 10); }
      if (document.getElementById('edit_clicks')) { document.getElementById('edit_clicks').value = site.clicks || 0; }
      document.getElementById('edit_icon_url').value = '';
      document.getElementById('edit_add_www').checked = false;
      if (document.getElementById('edit_prefer_https')) { document.getElementById('edit_prefer_https').checked = true; }
      document.getElementById('edit_sort_order').value = site.sort_order || 100;
      document.getElementById('edit_is_featured').checked = Number(site.is_featured) === 1;
      document.getElementById('edit_is_active').checked = Number(site.is_active) === 1;
      openModal('editSiteModal');
    });
  });

  if (window.addSitePrefill) {
    var addSiteModal = document.getElementById('addSiteModal');
    var addSiteForm = addSiteModal ? addSiteModal.querySelector('form') : null;
    var addValues = window.addSitePrefill;
    if (addSiteForm) {
      ['primary_url', 'backup_url', 'title', 'description', 'icon_url', 'sort_order'].forEach(function (name) {
        if (addSiteForm.elements[name] && typeof addValues[name] !== 'undefined') {
          addSiteForm.elements[name].value = addValues[name];
        }
      });
      if (addSiteForm.elements.category_id && addValues.category_id) { addSiteForm.elements.category_id.value = addValues.category_id; }
      if (addSiteForm.elements.add_www) { addSiteForm.elements.add_www.checked = Number(addValues.add_www) === 1; }
      var addHttpsPreference = getHttpsPreferenceCheckbox(addSiteForm);
      if (addHttpsPreference) { addHttpsPreference.checked = typeof addValues.prefer_https === 'undefined' ? true : Number(addValues.prefer_https) === 1; }
      if (addSiteForm.elements.is_featured) { addSiteForm.elements.is_featured.checked = Number(addValues.is_featured) === 1; }
      if (addSiteForm.elements.is_active) { addSiteForm.elements.is_active.checked = Number(addValues.is_active) === 1; }
      if (window.addSiteError) {
        var formError = document.createElement('div');
        formError.className = 'modal-form-error';
        formError.setAttribute('role', 'alert');
        formError.textContent = window.addSiteError;
        var header = addSiteModal.querySelector('.modal-header');
        if (header && header.parentNode) { header.parentNode.insertBefore(formError, header.nextSibling); }
      }
    }
  }

  if (window.editSiteError) {
    var editSiteModal = document.getElementById('editSiteModal');
    if (editSiteModal) {
      var previousEditError = editSiteModal.querySelector('[data-edit-site-error]');
      if (previousEditError && previousEditError.parentNode) { previousEditError.parentNode.removeChild(previousEditError); }
      var editFormError = document.createElement('div');
      editFormError.className = 'modal-form-error';
      editFormError.setAttribute('role', 'alert');
      editFormError.setAttribute('data-edit-site-error', '');
      editFormError.textContent = window.editSiteError;
      var editHeader = editSiteModal.querySelector('.modal-header');
      if (editHeader && editHeader.parentNode) { editHeader.parentNode.insertBefore(editFormError, editHeader.nextSibling); }
      openModal('editSiteModal');
    }
  }

  function showEditReadMessage(message, isError) {
    var editSiteModal = document.getElementById('editSiteModal');
    if (!editSiteModal) { return; }
    var previous = editSiteModal.querySelector('[data-edit-read-message]');
    if (previous && previous.parentNode) { previous.parentNode.removeChild(previous); }
    var output = document.createElement('div');
    output.className = isError ? 'modal-form-error' : 'modal-form-success';
    output.setAttribute('role', isError ? 'alert' : 'status');
    output.setAttribute('data-edit-read-message', '');
    output.textContent = message;
    var header = editSiteModal.querySelector('.modal-header');
    if (header && header.parentNode) { header.parentNode.insertBefore(output, header.nextSibling); }
  }

  function isGenericFetchedTitle(title, url) {
    var value = String(title || '').trim().toLowerCase();
    if (!value || value === '首页' || value === '主页' || value === 'home' || value === 'index') { return true; }
    var host = '';
    try { host = new URL(String(url || '')).hostname.toLowerCase(); } catch (error) { return false; }
    var hostWithoutWww = host.replace(/^www\./, '');
    return value === host || value === hostWithoutWww || value === 'www.' + hostWithoutWww;
  }

  var editSiteForm = document.getElementById('editSiteForm');
  var inspectEditButton = document.querySelector('[data-inspect-edit-site]');
  if (editSiteForm && inspectEditButton && window.fetch && window.FormData) {
    inspectEditButton.addEventListener('click', function () {
      var primaryInput = editSiteForm.elements.primary_url;
      if (!primaryInput || !String(primaryInput.value || '').trim()) {
        showEditReadMessage('请先填写主域名，再读取网站资料。', true);
        if (primaryInput) { primaryInput.focus(); }
        return;
      }
      inspectEditButton.disabled = true;
      var defaultText = inspectEditButton.textContent;
      inspectEditButton.textContent = '读取中…';
      showEditReadMessage('正在读取网站标题、介绍和图标，请保持当前窗口打开。', false);
      var payload = new FormData(editSiteForm);
      payload.set('action', 'inspect_edit_site_json');
      fetch(editSiteForm.getAttribute('action') || window.location.href, {
        method: 'POST',
        credentials: 'same-origin',
        body: payload
      }).then(function (response) {
        return response.text().then(function (text) {
          var data = null;
          try { data = JSON.parse(text); } catch (error) { throw new Error('读取服务返回异常，请稍后重试。'); }
          if (!response.ok || !data || !data.ok) { throw new Error(data && data.message ? data.message : '读取失败，请稍后重试。'); }
          return data;
        });
      }).then(function (data) {
        var metadata = data.metadata || {};
        var updatedFields = [];
        if (editSiteForm.elements.primary_url && metadata.primary_url) { editSiteForm.elements.primary_url.value = metadata.primary_url; }
        if (editSiteForm.elements.title && metadata.title && !isGenericFetchedTitle(metadata.title, metadata.primary_url)) { editSiteForm.elements.title.value = metadata.title; updatedFields.push('标题'); }
        if (editSiteForm.elements.description && typeof metadata.description !== 'undefined' && String(metadata.description || '').trim() !== '' && String(metadata.description || '').indexOf('暂未读取到网站介绍') !== 0) { editSiteForm.elements.description.value = metadata.description; updatedFields.push('介绍'); }
        if (editSiteForm.elements.icon_url && typeof metadata.icon_url !== 'undefined' && metadata.icon_url) { editSiteForm.elements.icon_url.value = metadata.icon_url; updatedFields.push('图标'); }
        var saveButton = editSiteForm.querySelector('[data-edit-save-button]');
        var saveHint = editSiteForm.querySelector('[data-edit-save-hint]');
        if (saveButton) { saveButton.classList.add('is-ready-to-save'); saveButton.focus(); }
        if (saveHint) { saveHint.textContent = '资料已回填但尚未写入列表，请点击“保存修改”完成更新。'; }
        showEditReadMessage((updatedFields.length > 0 ? '已回填：' + updatedFields.join('、') + '。' : '目标站未提供可用标题或介绍，已保留原资料。') + '尚未写入列表，请点击“保存修改”。', false);
        openModal('editSiteModal');
      }).catch(function (error) {
        showEditReadMessage(error && error.message ? error.message : '读取失败，请稍后重试。', true);
        openModal('editSiteModal');
      }).then(function () {
        inspectEditButton.disabled = false;
        inspectEditButton.textContent = defaultText;
      });
    });
  }

  function showAddReadMessage(message, isError) {
    var addSiteModal = document.getElementById('addSiteModal');
    if (!addSiteModal) { return; }
    var previous = addSiteModal.querySelector('[data-add-read-message]');
    if (previous && previous.parentNode) { previous.parentNode.removeChild(previous); }
    var output = document.createElement('div');
    output.className = isError ? 'modal-form-error' : 'modal-form-success';
    output.setAttribute('role', isError ? 'alert' : 'status');
    output.setAttribute('data-add-read-message', '');
    output.textContent = message;
    var header = addSiteModal.querySelector('.modal-header');
    if (header && header.parentNode) { header.parentNode.insertBefore(output, header.nextSibling); }
  }

  function getManualAddReadFallbackMessage(error) {
    var detail = error && error.message ? String(error.message).replace(/\s+/g, ' ').trim() : '';
    var message = '服务器暂时无法读取该网站资料，可能是 Cloudflare、地区访问限制或目标网站限制。当前窗口和已填写内容已保留；请直接填写“网站标题”和“网站介绍”，再点击“保存网站”。';
    return detail ? message + '（读取原因：' + detail + '）' : message;
  }

  var addSiteForm = document.getElementById('addSiteForm');
  var inspectAddButton = document.querySelector('[data-inspect-add-site]');
  if (addSiteForm && inspectAddButton && window.fetch && window.FormData) {
    inspectAddButton.addEventListener('click', function () {
      var primaryInput = addSiteForm.elements.primary_url;
      if (!primaryInput || !String(primaryInput.value || '').trim()) {
        showAddReadMessage('请先填写主域名，再读取网站资料。', true);
        if (primaryInput) { primaryInput.focus(); }
        return;
      }
      inspectAddButton.disabled = true;
      var defaultText = inspectAddButton.textContent;
      inspectAddButton.textContent = '读取中…';
      showAddReadMessage('正在读取网站标题、介绍和图标，请保持当前窗口打开。', false);
      var payload = new FormData(addSiteForm);
      payload.set('action', 'inspect_site_json');
      fetch(addSiteForm.getAttribute('action') || window.location.href, {
        method: 'POST',
        credentials: 'same-origin',
        body: payload
      }).then(function (response) {
        return response.text().then(function (text) {
          var data = null;
          try { data = JSON.parse(text); } catch (error) { throw new Error('读取服务返回异常，请稍后重试。'); }
          if (!response.ok || !data || !data.ok) { throw new Error(data && data.message ? data.message : '读取失败，请稍后重试。'); }
          return data;
        });
      }).then(function (data) {
        var metadata = data.metadata || {};
        var updatedFields = [];
        if (addSiteForm.elements.primary_url && metadata.primary_url) { addSiteForm.elements.primary_url.value = metadata.primary_url; updatedFields.push('主域名'); }
        if (addSiteForm.elements.title && metadata.title && (!isGenericFetchedTitle(metadata.title, metadata.primary_url) || !String(addSiteForm.elements.title.value || '').trim())) { addSiteForm.elements.title.value = metadata.title; updatedFields.push('标题'); }
        if (addSiteForm.elements.description && typeof metadata.description !== 'undefined' && String(metadata.description || '').trim() !== '' && String(metadata.description || '').indexOf('暂未读取到网站介绍') !== 0) { addSiteForm.elements.description.value = metadata.description; updatedFields.push('介绍'); }
        if (addSiteForm.elements.icon_url && metadata.icon_url) { addSiteForm.elements.icon_url.value = metadata.icon_url; updatedFields.push('图标'); }
        var saveButton = addSiteForm.querySelector('[data-add-save-button]');
        var saveHint = addSiteForm.querySelector('[data-add-save-hint]');
        if (saveButton) { saveButton.classList.add('is-ready-to-save'); saveButton.focus(); }
        if (saveHint) { saveHint.textContent = '资料已回填但尚未收录，请点击“保存网站”完成添加。'; }
        showAddReadMessage((updatedFields.length > 0 ? '已回填：' + updatedFields.join('、') + '。' : '目标站未提供可用标题或介绍，请手动补充。') + '尚未收录，请点击“保存网站”。', false);
        openModal('addSiteModal');
      }).catch(function (error) {
        var saveHint = addSiteForm.querySelector('[data-add-save-hint]');
        var titleInput = addSiteForm.elements.title;
        if (saveHint) { saveHint.textContent = '读取失败不会影响添加；请补充标题和介绍后，直接点击“保存网站”。'; }
        if (titleInput && !String(titleInput.value || '').trim()) { titleInput.focus(); }
        showAddReadMessage(getManualAddReadFallbackMessage(error), true);
        openModal('addSiteModal');
      }).then(function () {
        inspectAddButton.disabled = false;
        inspectAddButton.textContent = defaultText;
      });
    });
  }

  function showBatchImportMessage(message) {
    var modal = document.getElementById('batchImportModal');
    if (!modal || !message) { return; }
    var output = document.createElement('div');
    output.className = 'modal-form-error';
    output.setAttribute('role', 'alert');
    output.textContent = message;
    var header = modal.querySelector('.modal-header');
    if (header && header.parentNode) { header.parentNode.insertBefore(output, header.nextSibling); }
  }

  var batchImportForm = document.getElementById('batchImportForm');
  if (batchImportForm && window.batchImportPrefill) {
    var batchPrefill = window.batchImportPrefill;
    if (batchImportForm.elements.batch_sites) { batchImportForm.elements.batch_sites.value = batchPrefill.batch_sites || ''; }
    if (batchImportForm.elements.category_id && batchPrefill.category_id) { batchImportForm.elements.category_id.value = String(batchPrefill.category_id); }
    if (batchImportForm.elements.sort_order) { batchImportForm.elements.sort_order.value = String(typeof batchPrefill.sort_order === 'undefined' ? 100 : batchPrefill.sort_order); }
    if (batchImportForm.elements.add_www) { batchImportForm.elements.add_www.checked = !!batchPrefill.add_www; }
    var batchHttpsPreference = getHttpsPreferenceCheckbox(batchImportForm);
    if (batchHttpsPreference) { batchHttpsPreference.checked = typeof batchPrefill.prefer_https === 'undefined' ? true : !!batchPrefill.prefer_https; }
    if (batchImportForm.elements.is_active) { batchImportForm.elements.is_active.checked = !!batchPrefill.is_active; }
    if (batchImportForm.elements.is_featured) { batchImportForm.elements.is_featured.checked = !!batchPrefill.is_featured; }
    showBatchImportMessage(window.batchImportError || '批量添加未完成，请检查后重试。');
    openModal('batchImportModal');
  }

  document.addEventListener('click', function (event) {
    var button = event.target;
    while (button && button.nodeType === 1 && !button.hasAttribute('data-edit-category')) {
      button = button.parentNode;
    }
    if (!button || button.nodeType !== 1 || !button.hasAttribute('data-edit-category')) { return; }
    event.preventDefault();
    document.getElementById('edit_category_record_id').value = button.getAttribute('data-category-id') || '';
    document.getElementById('edit_category_name').value = button.getAttribute('data-category-name') || '';
    document.getElementById('edit_category_slug').value = button.getAttribute('data-category-slug') || '';
    document.getElementById('edit_category_sort_order').value = button.getAttribute('data-category-sort-order') || 0;
    document.getElementById('edit_category_home_limit').value = button.getAttribute('data-category-home-limit') || 0;
    document.getElementById('edit_category_home_mode').value = button.getAttribute('data-category-home-mode') || 'home';
    openModal('editCategoryModal');
  });

  var bulkForm = document.querySelector('[data-bulk-site-form]');
  if (bulkForm) {
    var items = Array.prototype.slice.call(document.querySelectorAll('[data-site-selection]'));
    var allCheckbox = document.querySelector('[data-select-all-sites-checkbox]');
    var countLabel = document.querySelector('[data-selected-site-count]');
    function updateSelectionState() {
      var selected = items.filter(function (item) { return item.checked; }).length;
      if (countLabel) { countLabel.textContent = '已选择 ' + selected + ' 项'; }
      if (allCheckbox) {
        allCheckbox.checked = items.length > 0 && selected === items.length;
        allCheckbox.indeterminate = selected > 0 && selected < items.length;
      }
    }
    function setAll(checked) {
      items.forEach(function (item) { item.checked = checked; });
      updateSelectionState();
    }
    items.forEach(function (item) { item.addEventListener('change', updateSelectionState); });
    if (allCheckbox) { allCheckbox.addEventListener('change', function () { setAll(allCheckbox.checked); }); }
    Array.prototype.forEach.call(document.querySelectorAll('[data-select-all-sites]'), function (button) {
      button.addEventListener('click', function () { setAll(true); });
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-invert-site-selection]'), function (button) {
      button.addEventListener('click', function () { items.forEach(function (item) { item.checked = !item.checked; }); updateSelectionState(); });
    });
    var bulkOperation = bulkForm.querySelector('[name="bulk_operation"]');
    var bulkActions = bulkForm.querySelector('.bulk-actions');
    var categorySource = document.querySelector('#addSiteModal select[name="category_id"]');
    if (bulkOperation && bulkActions && categorySource) {
      var categoryOption = document.createElement('option');
      categoryOption.value = 'set_category';
      categoryOption.textContent = '批量设置分类';
      bulkOperation.appendChild(categoryOption);
      var categorySelect = document.createElement('select');
      categorySelect.name = 'bulk_category_id';
      categorySelect.setAttribute('data-bulk-category-select', '');
      categorySelect.setAttribute('aria-label', '选择目标分类');
      categorySelect.disabled = true;
      categorySelect.style.display = 'none';
      var placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = '选择目标分类';
      categorySelect.appendChild(placeholder);
      Array.prototype.forEach.call(categorySource.options, function (option) {
        categorySelect.appendChild(option.cloneNode(true));
      });
      var actionButton = bulkActions.querySelector('button[type="submit"]');
      bulkActions.insertBefore(categorySelect, actionButton);
      bulkOperation.addEventListener('change', function () {
        var isCategoryOperation = bulkOperation.value === 'set_category';
        categorySelect.disabled = !isCategoryOperation;
        categorySelect.style.display = isCategoryOperation ? '' : 'none';
        if (!isCategoryOperation) { categorySelect.value = ''; }
      });
    }
    var headingActions = document.querySelector('.admin-heading-actions');
    if (headingActions && !headingActions.querySelector('[data-export-websites]')) {
      var importButton = document.createElement('button');
      importButton.className = 'button-secondary';
      importButton.type = 'button';
      importButton.textContent = '导入 CSV';
      importButton.addEventListener('click', function () { openModal('csvImportModal'); });
      headingActions.insertBefore(importButton, headingActions.firstChild);
      var exportForm = document.createElement('form');
      exportForm.method = 'post';
      exportForm.className = 'inline-export-form';
      exportForm.setAttribute('data-export-websites', '');
      var csrf = bulkForm.querySelector('[name="_csrf"]');
      exportForm.innerHTML = '<input type="hidden" name="action" value="export_websites_csv"><input type="hidden" name="_csrf" value="' + (csrf ? csrf.value : '') + '"><button class="button-secondary" type="submit">导出 CSV</button>';
      headingActions.insertBefore(exportForm, importButton);
    }
    updateSelectionState();
  }

  var overlay = null;
  var stageTimer = null;
  var slowTimer = null;
  var operationActions = ['bulk_site_action', 'bulk_import_sites', 'import_websites_csv', 'check_site', 'sync_icon', 'delete_site', 'inspect', 'inspect_edit_site', 'save_site', 'update_site', 'add_category', 'update_category', 'delete_category', 'move_category', 'save_site_settings', 'save_redirect_settings', 'update_admin_account', 'batch_category_display_settings'];

    function createOverlay() {
      if (overlay) { return overlay; }
      overlay = document.createElement('div');
      overlay.className = 'operation-overlay';
      overlay.setAttribute('role', 'status');
      overlay.setAttribute('aria-live', 'assertive');
      overlay.innerHTML = '<div class="operation-card"><div class="operation-spinner" aria-hidden="true"></div><strong data-operation-title>正在处理</strong><p data-operation-message>正在准备操作，请稍候…</p><div class="operation-progress" aria-hidden="true"><i></i></div><small>请勿关闭或刷新当前页面</small></div>';
      document.body.appendChild(overlay);
      return overlay;
    }

    function operationDetails(action, form, submitter) {
      var selected = document.querySelectorAll('[data-site-selection]:checked').length;
      var operation = form.querySelector('[name="bulk_operation"]');
      if (action === 'bulk_site_action') {
        if (operation && operation.value === 'check') { return { title: '正在批量检测', message: '正在检测 ' + selected + ' 个网站的主备域名…', button: '检测中…' }; }
        if (operation && operation.value === 'sync_icon') { return { title: '正在同步图标', message: '正在为 ' + selected + ' 个网站获取并保存图标…', button: '同步中…' }; }
        if (operation && operation.value === 'set_category') { return { title: '正在设置分类', message: '正在更新 ' + selected + ' 个网站的所属分类…', button: '设置中…' }; }
        return { title: '正在批量删除', message: '正在删除 ' + selected + ' 个网站及关联记录…', button: '删除中…', confirm: '确定批量删除已选择的 ' + selected + ' 个网站及其记录吗？' };
      }
      if (action === 'bulk_import_sites') { return { title: '正在批量添加', message: '正在逐行读取网站资料并同步图标，请稍候…', button: '导入中…' }; }
      if (action === 'import_websites_csv') { return { title: '正在导入 CSV', message: '正在校验分类、域名和重复记录，请稍候…', button: '导入中…' }; }
      if (action === 'check_site') { return { title: '正在检测网站', message: '正在检查主域名和备用域名是否可访问…', button: '检测中…' }; }
      if (action === 'sync_icon') { return { title: '正在同步图标', message: '正在获取并保存网站图标到本地…', button: '同步中…' }; }
      if (action === 'delete_site') { return { title: '正在删除网站', message: '正在删除网站及其关联记录…', button: '删除中…', confirm: '确定删除此网站及其记录吗？' }; }
      if (action === 'inspect' || action === 'inspect_edit_site') { return { title: '正在读取网站数据', message: '正在读取标题、介绍和图标信息…', button: '读取中…' }; }
      if (action === 'save_site' || action === 'update_site') { return { title: '正在保存网站', message: '正在校验域名并保存网站资料…', button: '保存中…' }; }
      if (action === 'add_category') { return { title: '正在添加分类', message: '正在校验分类名称和 URL 标识，请稍候…', button: '添加中…' }; }
      if (action === 'update_category') { return { title: '正在保存分类', message: '正在保存分类名称、URL 标识和优先级…', button: '保存中…' }; }
      if (action === 'delete_category') { return { title: '正在删除空分类', message: '正在确认分类未关联网站并删除分类…', button: '删除中…', confirm: '确定删除这个空分类吗？删除后无法恢复。' }; }
      if (action === 'move_category') { return { title: '正在调整分类顺序', message: '正在更新分类优先级，请稍候…', button: '调整中…' }; }
      if (action === 'save_site_settings') { return { title: '正在保存网站设置', message: '正在保存网站名称、简介与 SEO 信息…', button: '保存中…' }; }
      if (action === 'save_redirect_settings') { return { title: '正在保存跳转设置', message: '正在保存跳转模式、链接显示和模板文案…', button: '保存中…' }; }
      if (action === 'update_admin_account') { return { title: '正在更新账户安全设置', message: '正在校验当前密码并安全保存账户资料…', button: '更新中…' }; }
      return { title: '正在处理', message: '正在提交操作，请稍候…', button: '处理中…' };
    }

    function setBulkFormBusy(form, busy) {
      form.setAttribute('data-submitting', busy ? 'true' : 'false');
      form.setAttribute('aria-busy', busy ? 'true' : 'false');
      Array.prototype.forEach.call(form.querySelectorAll('button, input[type="submit"], select'), function (control) {
        control.disabled = busy;
        control.classList.toggle('is-submitting', busy);
      });
    }

    function runProgressiveNetworkBulk(form, task) {
      var selectedItems = Array.prototype.slice.call(document.querySelectorAll('[data-site-selection]:checked'));
      var selectedIds = selectedItems.map(function (item) { return item.value; });
      var csrf = form.querySelector('[name="_csrf"]');
      if (!window.fetch || !csrf) {
        window.alert('当前浏览器不支持安全分段处理。请每次最多选择 20 个网站后重试。');
        return;
      }
      var taskLabel = task === 'check' ? '批量检测' : '批量同步图标';
      if (!window.confirm(taskLabel + '将逐站处理 ' + selectedIds.length + ' 个网站。大量网站可能需要较长时间，但不会阻塞后台；请保持当前页面打开。是否开始？')) {
        return;
      }

      setBulkFormBusy(form, true);
      var activeOverlay = createOverlay();
      var titleNode = activeOverlay.querySelector('[data-operation-title]');
      var messageNode = activeOverlay.querySelector('[data-operation-message]');
      titleNode.textContent = task === 'check' ? '正在分段检测网站' : '正在分段同步图标';
      activeOverlay.classList.add('is-visible');
      var completed = 0;
      var success = 0;
      var failed = [];
      var endpoint = form.getAttribute('action') || window.location.href;

      function updateProgress(title) {
        messageNode.textContent = '已处理 ' + completed + ' / ' + selectedIds.length + ' 个；成功 ' + success + ' 个，未完成 ' + failed.length + ' 个。' + (title ? ' 当前：' + title : '');
      }

      function finish() {
        setBulkFormBusy(form, false);
        activeOverlay.classList.remove('is-visible');
        var summary = taskLabel + '已完成：成功 ' + success + ' 个，未完成 ' + failed.length + ' 个。';
        if (failed.length > 0) { summary += '\n未完成：' + failed.slice(0, 6).join('、') + (failed.length > 6 ? ' 等。' : ''); }
        window.alert(summary);
        window.location.reload();
      }

      function processNext() {
        if (completed >= selectedIds.length) {
          finish();
          return;
        }
        var currentId = selectedIds[completed];
        var currentItem = selectedItems[completed];
        var currentTitle = currentItem ? (currentItem.getAttribute('aria-label') || '').replace(/^选择\s*/, '') : '';
        updateProgress(currentTitle);
        var body = 'action=process_site_task&task=' + encodeURIComponent(task) + '&website_id=' + encodeURIComponent(currentId) + '&_csrf=' + encodeURIComponent(csrf.value);
        window.fetch(endpoint, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
          body: body
        }).then(function (response) {
          return response.json();
        }).then(function (result) {
          completed++;
          if (result && result.ok) {
            success++;
          } else {
            failed.push(currentTitle || '网站 #' + currentId);
          }
          window.setTimeout(processNext, 40);
        }).catch(function () {
          completed++;
          failed.push(currentTitle || '网站 #' + currentId);
          window.setTimeout(processNext, 40);
        });
      }

      updateProgress('准备开始');
      processNext();
    }

    function resetSubmittingForms() {
      Array.prototype.forEach.call(document.querySelectorAll('form[data-submitting="true"]'), function (form) {
        form.removeAttribute('data-submitting');
        form.removeAttribute('aria-busy');
        Array.prototype.forEach.call(form.querySelectorAll('button, input[type="submit"]'), function (button) {
          button.disabled = false;
          if (button.hasAttribute('data-original-label')) {
            button.textContent = button.getAttribute('data-original-label');
            button.removeAttribute('data-original-label');
          }
        });
      });
      if (overlay) { overlay.classList.remove('is-visible'); }
      window.clearTimeout(stageTimer);
      window.clearTimeout(slowTimer);
    }

    document.addEventListener('submit', function (event) {
      var form = event.target;
      if (!form || form.nodeName !== 'FORM') { return; }
      var submitter = event.submitter || document.activeElement;
      var actionInput = form.querySelector('input[name="action"]');
      var action = submitter && submitter.name === 'action' ? submitter.value : (actionInput ? actionInput.value : '');
      if (operationActions.indexOf(action) === -1) { return; }
      if (form.getAttribute('data-submitting') === 'true') {
        event.preventDefault();
        return;
      }
      if (action === 'bulk_site_action' && document.querySelectorAll('[data-site-selection]:checked').length === 0) {
        event.preventDefault();
        window.alert('请先选择至少一个网站。');
        return;
      }
      if (action === 'bulk_site_action') {
        var networkOperation = form.querySelector('[name="bulk_operation"]');
        if (networkOperation && (networkOperation.value === 'check' || networkOperation.value === 'sync_icon')) {
          event.preventDefault();
          runProgressiveNetworkBulk(form, networkOperation.value);
          return;
        }
      }
      var details = operationDetails(action, form, submitter);
      if (details.confirm && !window.confirm(details.confirm)) {
        event.preventDefault();
        return;
      }
      form.setAttribute('data-submitting', 'true');
      form.setAttribute('aria-busy', 'true');
      Array.prototype.forEach.call(form.querySelectorAll('button, input[type="submit"]'), function (button) {
        if (!button.disabled) {
          button.setAttribute('data-original-label', button.textContent);
          button.disabled = true;
          button.classList.add('is-submitting');
        }
      });
      if (submitter && submitter.nodeName === 'BUTTON') { submitter.textContent = details.button; }
      var activeOverlay = createOverlay();
      activeOverlay.querySelector('[data-operation-title]').textContent = details.title;
      activeOverlay.querySelector('[data-operation-message]').textContent = details.message;
      activeOverlay.classList.add('is-visible');
      stageTimer = window.setTimeout(function () {
        activeOverlay.querySelector('[data-operation-message]').textContent = '服务器正在逐项处理，请保持当前页面打开…';
      }, 900);
      slowTimer = window.setTimeout(function () {
        activeOverlay.querySelector('[data-operation-message]').textContent = '操作仍在进行中，网站较多或响应较慢时需要更长时间…';
      }, 8000);
    }, true);

  (function mergeHomepageSettings() {
    var sourcePanel = document.getElementById('homepage-copy-settings');
    var settingsPanel = document.getElementById('site-settings');
    if (!sourcePanel || !settingsPanel) { return; }
    var sourceForm = sourcePanel.querySelector('form');
    var targetForm = settingsPanel.querySelector('form');
    var saveButton = targetForm ? targetForm.querySelector('button[type="submit"]') : null;
    if (!sourceForm || !targetForm || !saveButton || !saveButton.parentNode) { return; }
    var insertionPoint = saveButton.parentNode;
    var section = document.createElement('div');
    section.className = 'settings-subsection span-12';
    section.innerHTML = '<h3>首页展示文案</h3><p>设置前台顶部目录名称、分类提示、首页主视觉说明及侧栏功能标签。保存后会直接应用到首页。</p>';
    targetForm.insertBefore(section, insertionPoint);
    Array.prototype.forEach.call(sourceForm.querySelectorAll('.form-field'), function (field) {
      targetForm.insertBefore(field, insertionPoint);
    });
    var subtitleField = targetForm.querySelector('[name="site_subtitle"]');
    if (subtitleField && subtitleField.parentNode) {
      var subtitleLabel = subtitleField.parentNode.querySelector('label');
      var subtitleHint = subtitleField.parentNode.querySelector('.form-hint');
      if (subtitleLabel) { subtitleLabel.textContent = '网站简介（SEO 默认文案）'; }
      if (subtitleHint) { subtitleHint.textContent = '用于 SEO 默认标题和简介；首页说明请在下方单独设置。'; }
    }
    var brandMarkInput = targetForm.querySelector('[name="brand_mark"]');
    function updateBrandMarks() {
      var brandMark = brandMarkInput && brandMarkInput.value ? brandMarkInput.value : 'N';
      Array.prototype.forEach.call(document.querySelectorAll('.brand-mark'), function (mark) {
        mark.textContent = brandMark;
      });
    }
    if (brandMarkInput) { brandMarkInput.addEventListener('input', updateBrandMarks); }
    updateBrandMarks();
    sourcePanel.parentNode.removeChild(sourcePanel);
  }());

  window.addEventListener('pageshow', resetSubmittingForms);
}());
