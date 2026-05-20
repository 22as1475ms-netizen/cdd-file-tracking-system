<?php
?>
</div>
<div class="cddfts-notification-toast-stack" id="cddftsNotificationToastStack" aria-live="polite" aria-atomic="false"></div>
<div class="modal fade" id="cddftsConfirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content cddfts-confirm-modal">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title" id="cddftsConfirmModalTitle">Confirm action</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body pt-2">
        <p class="mb-3" id="cddftsConfirmModalMessage"></p>
        <div class="cddfts-confirm-modal__helper d-none" id="cddftsConfirmModalHelper"></div>
        <div class="cddfts-confirm-modal__warning d-none" id="cddftsConfirmModalWarning"></div>
        <div id="cddftsConfirmPasswordWrap" class="d-none">
          <label class="form-label small text-muted" for="cddftsConfirmPasswordInput" id="cddftsConfirmPasswordLabel">Confirm password</label>
          <div class="cddfts-confirm-modal__password-field">
            <input type="password" class="form-control" id="cddftsConfirmPasswordInput" autocomplete="current-password">
            <button type="button" class="cddfts-confirm-modal__password-toggle" id="cddftsConfirmPasswordToggle" aria-label="Show password" aria-pressed="false">
              <i class="bi bi-eye"></i>
            </button>
          </div>
        </div>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-light" data-action="cancel">Cancel</button>
        <button type="button" class="btn btn-primary" data-action="confirm" id="cddftsConfirmOkBtn">Confirm</button>
      </div>
    </div>
  </div>
</div>
<?php
if (!empty($show_admin_status_footer) && is_array($statusCounts)):
?>
  <footer class="admin-status-footer" aria-label="Routing status">
    <div class="admin-status-footer__content">
      <div class="admin-status-footer__metrics">
        <div class="admin-status-metric">
          <i class="bi bi-exclamation-circle admin-status-metric__icon admin-status-metric__icon--warning"></i>
          <div class="admin-status-metric__data">
            <strong><?= (int)($statusCounts['waiting'] ?? 0) ?></strong>
            <span>Waiting</span>
          </div>
        </div>
        <div class="admin-status-metric">
          <i class="bi bi-check-circle admin-status-metric__icon admin-status-metric__icon--success"></i>
          <div class="admin-status-metric__data">
            <strong><?= (int)($statusCounts['routed'] ?? 0) ?></strong>
            <span>Routed</span>
          </div>
        </div>
        <div class="admin-status-metric">
          <i class="bi bi-stack admin-status-metric__icon admin-status-metric__icon--info"></i>
          <div class="admin-status-metric__data">
            <strong><?= (int)($statusCounts['total'] ?? 0) ?></strong>
            <span>Total</span>
          </div>
        </div>
      </div>
    </div>
  </footer>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
  (function () {
    const body = document.body;
    const skipPrefixes = [
      '/documents/download',
      '/admin/users/export',
      '/admin/logs/export'
    ];

    const isModifiedClick = function (event) {
      return event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0;
    };

    document.addEventListener('click', function (event) {
      const link = event.target.closest('a[href]');
      if (!link) return;
      if (link.target === '_blank' || link.hasAttribute('download')) return;
      if (isModifiedClick(event)) return;

      const href = link.getAttribute('href') || '';
      if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;
      if (link.origin !== window.location.origin) return;
      if (skipPrefixes.some(function (prefix) { return link.pathname.startsWith('<?= BASE_URL ?>' + prefix) || link.pathname.endsWith(prefix); })) return;

      body.classList.add('is-leaving');
    }, true);

    window.addEventListener('pageshow', function () {
      body.classList.remove('is-leaving');
    });

    const modalEl = document.getElementById('cddftsConfirmModal');
    const modalTitle = document.getElementById('cddftsConfirmModalTitle');
    const modalMessage = document.getElementById('cddftsConfirmModalMessage');
    const passwordWrap = document.getElementById('cddftsConfirmPasswordWrap');
    const helperEl = document.getElementById('cddftsConfirmModalHelper');
    const warningEl = document.getElementById('cddftsConfirmModalWarning');
    const passwordInput = document.getElementById('cddftsConfirmPasswordInput');
    const passwordLabel = document.getElementById('cddftsConfirmPasswordLabel');
    const passwordToggle = document.getElementById('cddftsConfirmPasswordToggle');
    const confirmBtn = document.getElementById('cddftsConfirmOkBtn');
    const cancelBtn = modalEl ? modalEl.querySelector('[data-action="cancel"]') : null;
    const bsModal = modalEl ? new bootstrap.Modal(modalEl) : null;
    const toggleColorModeBtn = document.getElementById('toggle-color-mode');
    const toggleColorModeIcon = document.getElementById('toggle-color-mode-icon');
    const toggleColorModeLabel = document.getElementById('toggle-color-mode-label');
    let resolver = null;

    const getStoredColorMode = function () {
      const value = document.documentElement.getAttribute('data-color-mode');
      return value === 'dark' ? 'dark' : 'light';
    };

    const detectPerformanceTier = function () {
      let prefersReducedMotion = false;
      let saveData = false;
      let deviceMemory = 8;
      let hardwareConcurrency = 8;
      try {
        prefersReducedMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
        saveData = !!(navigator.connection && navigator.connection.saveData);
        deviceMemory = Number(navigator.deviceMemory || 8);
        hardwareConcurrency = Number(navigator.hardwareConcurrency || 8);
      } catch (_err) {}
      return (prefersReducedMotion || saveData || deviceMemory <= 4 || hardwareConcurrency <= 4) ? 'lite' : 'full';
    };

    const setColorMode = function (mode) {
      const resolved = mode === 'dark' ? 'dark' : 'light';
      document.documentElement.setAttribute('data-color-mode', resolved);
      try { localStorage.setItem('cddfts-color-mode', resolved); } catch (_err) {}
      if (toggleColorModeIcon) {
        toggleColorModeIcon.className = 'bi ' + (resolved === 'dark' ? 'bi-moon-stars-fill' : 'bi-sun');
      }
      if (toggleColorModeLabel) {
        toggleColorModeLabel.textContent = resolved === 'dark' ? 'On' : 'Off';
      }
      if (toggleColorModeBtn) {
        toggleColorModeBtn.setAttribute('aria-checked', resolved === 'dark' ? 'true' : 'false');
      }
    };

    document.documentElement.setAttribute('data-performance-tier', detectPerformanceTier());
    setColorMode(getStoredColorMode());

    if (toggleColorModeBtn) {
      toggleColorModeBtn.addEventListener('click', function () {
        setColorMode(getStoredColorMode() === 'dark' ? 'light' : 'dark');
      });
    }

    window.cddftsConfirmModal = function (options) {
      return new Promise(function (resolve) {
        if (!modalEl || !bsModal) {
          resolve({ ok: window.confirm(options.message || 'Proceed?'), password: '' });
          return;
        }

        resolver = resolve;
        modalTitle.textContent = options.title || 'Confirm action';
        modalMessage.textContent = options.message || 'Proceed with this action?';
        helperEl.textContent = options.helperText || '';
        helperEl.classList.toggle('d-none', !options.helperText);
        warningEl.textContent = options.warningText || '';
        warningEl.classList.toggle('d-none', !options.warningText);
        confirmBtn.textContent = options.confirmText || 'Confirm';
        passwordWrap.classList.toggle('d-none', !options.requirePassword);
        passwordLabel.textContent = options.passwordLabel || 'Confirm password';
        passwordInput.value = '';
        passwordInput.type = 'password';
        if (passwordToggle) {
          passwordToggle.setAttribute('aria-pressed', 'false');
          passwordToggle.setAttribute('aria-label', 'Show password');
          passwordToggle.innerHTML = '<i class="bi bi-eye"></i>';
        }
        bsModal.show();
        if (options.requirePassword) {
          window.setTimeout(function () { passwordInput.focus(); }, 120);
        }
      });
    };

    const closeWith = function (ok) {
      if (!resolver) return;
      const payload = { ok: !!ok, password: passwordInput ? passwordInput.value : '' };
      const callback = resolver;
      resolver = null;
      bsModal.hide();
      callback(payload);
    };

    if (confirmBtn) {
      confirmBtn.addEventListener('click', function () { closeWith(true); });
    }
    if (cancelBtn) {
      cancelBtn.addEventListener('click', function () { closeWith(false); });
    }
    if (passwordToggle && passwordInput) {
      passwordToggle.addEventListener('click', function () {
        const showing = passwordInput.type === 'text';
        passwordInput.type = showing ? 'password' : 'text';
        passwordToggle.setAttribute('aria-pressed', showing ? 'false' : 'true');
        passwordToggle.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
        passwordToggle.innerHTML = showing ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
        passwordInput.focus();
      });
    }
    if (modalEl) {
      modalEl.addEventListener('hidden.bs.modal', function () {
        if (resolver) {
          const callback = resolver;
          resolver = null;
          callback({ ok: false, password: '' });
        }
      });
    }

    document.addEventListener('submit', function (event) {
      const form = event.target.closest('form.js-confirm');
      if (!form || form.dataset.confirmBound === '1') return;
      form.dataset.confirmBound = '1';
    }, true);

    document.querySelectorAll('form.js-confirm').forEach(function (form) {
      if (form.dataset.confirmBound === '1') return;
      form.dataset.confirmBound = '1';
      form.addEventListener('submit', async function (event) {
        event.preventDefault();
        const result = await window.cddftsConfirmModal({
          title: form.getAttribute('data-confirm-title') || 'Confirm action',
          message: form.getAttribute('data-confirm-message') || 'Proceed with this action?',
          confirmText: form.getAttribute('data-confirm-button') || 'Confirm',
          requirePassword: form.getAttribute('data-confirm-password') === '1',
          passwordLabel: form.getAttribute('data-confirm-password-label') || 'Confirm password'
        });
        if (!result.ok) return;
        if (result.password) {
          let hidden = form.querySelector('input[name="confirm_password"][data-generated="1"]');
          if (!hidden) {
            hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'confirm_password';
            hidden.setAttribute('data-generated', '1');
            form.appendChild(hidden);
          }
          hidden.value = result.password;
        }
        HTMLFormElement.prototype.submit.call(form);
      });
    });

    document.querySelectorAll('.alert.auto-dismiss').forEach(function (alertEl) {
      window.setTimeout(function () {
        alertEl.style.opacity = '0';
        alertEl.style.transform = 'translateY(-8px)';
        alertEl.style.maxHeight = '0';
        alertEl.style.marginTop = '0';
        alertEl.style.marginBottom = '0';
        alertEl.style.paddingTop = '0';
        alertEl.style.paddingBottom = '0';
        window.setTimeout(function () {
          if (alertEl.parentNode) {
            alertEl.parentNode.removeChild(alertEl);
          }
        }, 280);
      }, 5000);
    });

    (function () {
      if (!window.history || typeof window.history.replaceState !== 'function') {
        return;
      }

      const params = new URLSearchParams(window.location.search);
      const flashKeys = ['msg', 'err', 'created_email', 'created_password'];
      let changed = false;

      flashKeys.forEach(function (key) {
        if (params.has(key)) {
          params.delete(key);
          changed = true;
        }
      });

      if (!changed) {
        return;
      }

      const nextQuery = params.toString();
      const nextUrl = window.location.pathname + (nextQuery ? '?' + nextQuery : '') + window.location.hash;
      window.history.replaceState({}, document.title, nextUrl);
    })();

    if (window.flatpickr) {
      document.querySelectorAll('[data-date-picker]').forEach(function (input) {
        const picker = window.flatpickr(input, {
          dateFormat: 'Y-m-d',
          altInput: true,
          altFormat: 'F j, Y',
          allowInput: true,
          disableMobile: true,
          monthSelectorType: 'static',
          parseDate: function (dateStr, format) {
            const value = String(dateStr || '').trim();
            if (!value) {
              return null;
            }

            let parsed = window.flatpickr.parseDate(value, format);
            if (parsed instanceof Date && !isNaN(parsed.getTime())) {
              return parsed;
            }

            parsed = window.flatpickr.parseDate(value, 'Y-m-d');
            if (parsed instanceof Date && !isNaN(parsed.getTime())) {
              return parsed;
            }

            parsed = window.flatpickr.parseDate(value, 'm/d/Y');
            if (parsed instanceof Date && !isNaN(parsed.getTime())) {
              return parsed;
            }

            parsed = window.flatpickr.parseDate(value, 'n/j/Y');
            if (parsed instanceof Date && !isNaN(parsed.getTime())) {
              return parsed;
            }

            const nativeDate = new Date(value);
            if (!isNaN(nativeDate.getTime())) {
              return nativeDate;
            }

            return null;
          },
          nextArrow: '<i class="bi bi-arrow-right"></i>',
          prevArrow: '<i class="bi bi-arrow-left"></i>'
        });

        const shell = input.closest('[data-date-picker-shell]');
        const toggle = shell ? shell.querySelector('[data-date-picker-toggle]') : null;
        if (toggle) {
          toggle.addEventListener('click', function () {
            picker.open();
            if (picker.altInput) {
              picker.altInput.focus();
            }
          });
        }
      });
    }

    const alertsCountEl = document.getElementById('app-alert-count');
    const alertsDotEl = document.getElementById('app-alert-dot');
    const alertsItemsEl = document.getElementById('app-alert-items');
    const alertsToggleEl = document.getElementById('app-alert-toggle');
    const notificationToastStack = document.getElementById('cddftsNotificationToastStack');
    const syncToastOffset = function () {
      const statusFooter = document.querySelector('.admin-status-footer');
      const baseOffset = 20;
      const footerOffset = statusFooter ? Math.ceil(statusFooter.getBoundingClientRect().height) + 16 : baseOffset;
      document.documentElement.style.setProperty('--cddfts-toast-bottom-offset', footerOffset + 'px');
    };
    syncToastOffset();
    window.addEventListener('resize', syncToastOffset);
    if (alertsCountEl && alertsDotEl && alertsItemsEl) {
      const baseUrl = '<?= BASE_URL ?>';
      const csrfToken = '<?= e(csrf_token()) ?>';
      const initialNotificationItems = Array.isArray(window.cddftsInitialNotifications) ? window.cddftsInitialNotifications : [];
      const notificationViewerId = Number(window.cddftsNotificationViewerId || 0);
      const seenNotificationStorageKey = notificationViewerId > 0
        ? 'cddfts-seen-notification-ids:' + notificationViewerId
        : 'cddfts-seen-notification-ids';
      const readSeenNotificationIds = function () {
        try {
          const raw = window.sessionStorage.getItem(seenNotificationStorageKey);
          const parsed = raw ? JSON.parse(raw) : [];
          if (!Array.isArray(parsed)) {
            return [];
          }
          return parsed
            .map(function (value) { return Number(value || 0); })
            .filter(function (value) { return value > 0; });
        } catch (_err) {
          return [];
        }
      };
      const persistSeenNotificationIds = function (ids) {
        try {
          const snapshot = Array.from(ids).filter(function (value) {
            return Number(value || 0) > 0;
          }).slice(-120);
          window.sessionStorage.setItem(seenNotificationStorageKey, JSON.stringify(snapshot));
        } catch (_err) {}
      };
      const seenNotificationIds = new Set(readSeenNotificationIds());
      initialNotificationItems.map(function (item) {
        return Number(item && item.id ? item.id : 0);
      }).filter(function (id) {
        return id > 0;
      }).forEach(function (id) {
        seenNotificationIds.add(id);
      });
      persistSeenNotificationIds(seenNotificationIds);
      const escapeHtml = function (value) {
        return String(value || '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#39;');
      };
      const normalizeLink = function (link) {
        const href = String(link || '').trim();
        if (!href || href === '/documents' || href.endsWith('/documents')) {
          return '';
        }
        if (href.startsWith('http://') || href.startsWith('https://')) {
          return href;
        }
        if (href.startsWith('/')) {
          return baseUrl + href;
        }
        return baseUrl + '/' + href;
      };
      const classifyNotification = function (item) {
        const text = ((item && item.title ? item.title : '') + ' ' + (item && item.body ? item.body : '')).toLowerCase();
        if (text.includes('reject') || text.includes('denied') || text.includes('failed') || text.includes('error')) {
          return { tone: 'danger', icon: 'bi-x-circle-fill' };
        }
        if (text.includes('approved') || text.includes('accepted') || text.includes('success')) {
          return { tone: 'success', icon: 'bi-check-circle-fill' };
        }
        if (text.includes('review') || text.includes('pending') || text.includes('request')) {
          return { tone: 'warning', icon: 'bi-exclamation-circle-fill' };
        }
        return { tone: 'info', icon: 'bi-bell-fill' };
      };
      const applyAlerts = function (payload) {
        const count = Number(payload && payload.count ? payload.count : 0);
        const items = Array.isArray(payload && payload.items) ? payload.items : [];
        alertsCountEl.textContent = String(count);
        alertsDotEl.classList.toggle('d-none', count <= 0);

        if (!items.length) {
          alertsItemsEl.innerHTML = '<div class="text-muted small px-2 py-1">No notifications.</div>';
          return;
        }

        alertsItemsEl.innerHTML = items.map(function (item) {
          const marker = classifyNotification(item);
          const isRead = !!(item && item.is_read);
          const title = escapeHtml(item.title || '');
          const body = escapeHtml(item.body || '');
          const resolvedLink = normalizeLink(item.link || '');
          const rowClass = 'dropdown-item app-notification-item app-notification-item--' + marker.tone + (isRead ? ' is-read' : '');
          if (!resolvedLink) {
            return '<div class="' + rowClass + '">'
              + '<span class="app-notification-item__icon"><i class="bi ' + marker.icon + '"></i></span>'
              + '<span class="app-notification-item__content"><strong>' + title + '</strong><small>' + body + '</small></span>'
              + '</div>';
          }
          return '<a class="' + rowClass + '" href="' + escapeHtml(resolvedLink) + '">'
            + '<span class="app-notification-item__icon"><i class="bi ' + marker.icon + '"></i></span>'
            + '<span class="app-notification-item__content"><strong>' + title + '</strong><small>' + body + '</small></span>'
            + '</a>';
        }).join('');
      };
      const pulseNotificationBell = function () {
        if (!alertsToggleEl) {
          return;
        }
        alertsToggleEl.classList.remove('is-catching');
        void alertsToggleEl.offsetWidth;
        alertsToggleEl.classList.add('is-catching');
        window.setTimeout(function () {
          alertsToggleEl.classList.remove('is-catching');
        }, 520);
      };
      const animateToastToBell = function (toastEl, callback) {
        const prefersReducedMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
        if (!toastEl || !toastEl.parentNode || !alertsToggleEl || prefersReducedMotion) {
          callback();
          pulseNotificationBell();
          return;
        }

        const toastRect = toastEl.getBoundingClientRect();
        const bellRect = alertsToggleEl.getBoundingClientRect();
        const ghost = toastEl.cloneNode(true);
        ghost.classList.remove('is-visible');
        ghost.classList.add('cddfts-notification-toast-ghost');
        ghost.style.top = toastRect.top + 'px';
        ghost.style.left = toastRect.left + 'px';
        ghost.style.width = toastRect.width + 'px';
        ghost.style.height = toastRect.height + 'px';
        ghost.style.setProperty('--cddfts-toast-fly-x', ((bellRect.left + (bellRect.width / 2)) - (toastRect.left + (toastRect.width / 2))) + 'px');
        ghost.style.setProperty('--cddfts-toast-fly-y', ((bellRect.top + (bellRect.height / 2)) - (toastRect.top + (toastRect.height / 2))) + 'px');
        document.body.appendChild(ghost);

        callback();

        window.requestAnimationFrame(function () {
          ghost.classList.add('is-flying');
        });

        window.setTimeout(function () {
          if (ghost.parentNode) {
            ghost.parentNode.removeChild(ghost);
          }
          pulseNotificationBell();
        }, 520);
      };
      const removeToast = function (toastEl) {
        if (!toastEl || !toastEl.parentNode) {
          return;
        }
        toastEl.classList.remove('is-visible');
        window.setTimeout(function () {
          if (toastEl.parentNode) {
            toastEl.parentNode.removeChild(toastEl);
          }
        }, 220);
      };
      const dismissToast = function (toastEl, options) {
        const config = options || {};
        if (!toastEl) {
          if (typeof config.onComplete === 'function') {
            config.onComplete();
          }
          return;
        }

        animateToastToBell(toastEl, function () {
          removeToast(toastEl);
          if (typeof config.onComplete === 'function') {
            window.setTimeout(function () {
              config.onComplete();
            }, 140);
          }
        });
      };
      const showNotificationToast = function (item) {
        if (!notificationToastStack || !item || item.is_read) {
          return;
        }

        const marker = classifyNotification(item);
        const resolvedLink = normalizeLink(item.link || '');
        const toastEl = document.createElement('div');
        toastEl.className = 'cddfts-notification-toast cddfts-notification-toast--' + marker.tone;
        toastEl.setAttribute('role', 'status');
        toastEl.innerHTML = ''
          + '<button type="button" class="cddfts-notification-toast__close" aria-label="Dismiss notification"><i class="bi bi-x-lg"></i></button>'
          + '<div class="cddfts-notification-toast__icon"><i class="bi ' + marker.icon + '"></i></div>'
          + '<div class="cddfts-notification-toast__content">'
          + '  <span class="cddfts-notification-toast__eyebrow">New notification</span>'
          + '  <strong>' + escapeHtml(item.title || '') + '</strong>'
          + '  <small>' + escapeHtml(item.body || '') + '</small>'
          + (resolvedLink ? '<span class="cddfts-notification-toast__cta">Open</span>' : '')
          + '</div>';

        const closeBtn = toastEl.querySelector('.cddfts-notification-toast__close');
        if (closeBtn) {
          closeBtn.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            dismissToast(toastEl);
          });
        }

        if (resolvedLink) {
          toastEl.classList.add('is-actionable');
          toastEl.addEventListener('click', function (event) {
            if (event.target.closest('.cddfts-notification-toast__close')) {
              return;
            }
            event.preventDefault();
            dismissToast(toastEl, {
              onComplete: function () {
                window.location.href = resolvedLink;
              }
            });
          });
        }

        notificationToastStack.prepend(toastEl);
        window.requestAnimationFrame(function () {
          toastEl.classList.add('is-visible');
        });

        window.setTimeout(function () {
          dismissToast(toastEl);
        }, 5200);
      };
      const notifyNewAlerts = function (items) {
        items.forEach(function (item) {
          const id = Number(item && item.id ? item.id : 0);
          if (id <= 0 || seenNotificationIds.has(id)) {
            return;
          }
          seenNotificationIds.add(id);
          persistSeenNotificationIds(seenNotificationIds);
          showNotificationToast(item);
        });
      };
      const pollAlerts = async function () {
        try {
          const response = await fetch(baseUrl + '/api/notifications/unread', {
            method: 'GET',
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store'
          });
          if (!response.ok) return;
          const payload = await response.json();
          const items = Array.isArray(payload && payload.items) ? payload.items : [];
          notifyNewAlerts(items);
          applyAlerts(payload);
        } catch (_err) {}
      };

      pollAlerts();
      window.setInterval(function () {
        if (document.visibilityState === 'hidden') {
          return;
        }
        pollAlerts();
      }, 30000);

      const markReadForm = document.getElementById('mark-all-read-form');
      if (markReadForm) {
        markReadForm.addEventListener('submit', async function (event) {
          event.preventDefault();
          try {
            const response = await fetch(markReadForm.action, {
              method: 'POST',
              body: new FormData(markReadForm),
              headers: {
                'Accept': 'application/json',
                'X-CSRF-Token': csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
              },
              credentials: 'same-origin'
            });
            if (!response.ok) return;
            alertsCountEl.textContent = '0';
            alertsDotEl.classList.add('d-none');
            alertsItemsEl.querySelectorAll('.app-notification-item').forEach(function (el) {
              el.classList.add('is-read');
            });
          } catch (_err) {}
        });
      }

      const clearAllForm = document.getElementById('clear-all-notifications-form');
      if (clearAllForm) {
        clearAllForm.addEventListener('submit', async function (event) {
          event.preventDefault();
          try {
            const response = await fetch(clearAllForm.action, {
              method: 'POST',
              body: new FormData(clearAllForm),
              headers: {
                'Accept': 'application/json',
                'X-CSRF-Token': csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
              },
              credentials: 'same-origin'
            });
            if (!response.ok) return;
            alertsCountEl.textContent = '0';
            alertsDotEl.classList.add('d-none');
            alertsItemsEl.innerHTML = '<div class="text-muted small px-2 py-1">No notifications.</div>';
          } catch (_err) {}
        });
      }
    }
  })();
</script>
</body>
</html>
