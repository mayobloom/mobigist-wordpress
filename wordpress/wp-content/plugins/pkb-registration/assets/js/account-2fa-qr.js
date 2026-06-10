(function () {
  function renderQr() {
    var link = document.querySelector('#two-factor-qr-code a');
    var url = link ? link.getAttribute('href') : '';

    if (!link || !url || url.indexOf('otpauth://') !== 0 || typeof window.qrcode !== 'function') {
      return;
    }

    if (link.querySelector('svg')) {
      return;
    }

    var qr = window.qrcode(0, 'L');
    qr.addData(url);
    qr.make();
    link.innerHTML = qr.createSvgTag(5);

    var svg = link.querySelector('svg');
    if (!svg) {
      return;
    }

    var title = document.createElement('title');
    title.textContent = window.pkbRegistration2fa && window.pkbRegistration2fa.qrCodeLabel
      ? window.pkbRegistration2fa.qrCodeLabel
      : 'Authenticator App QR Code';
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', title.textContent);
    svg.appendChild(title);
  }

  function shortenPrimaryMethodOptions() {
    var totpOption = document.querySelector('#two-factor-primary-provider option[value="Two_Factor_Totp"]');

    if (totpOption) {
      totpOption.textContent = '인증 앱';
    }
  }

  function ensureAtLeastOneProviderSelected() {
    var enabledProviders = Array.prototype.slice.call(
      document.querySelectorAll('input[name="_two_factor_enabled_providers[]"][value]:not([value=""])')
    );
    var hasEnabledProvider = enabledProviders.some(function (input) {
      return input.checked;
    });

    if (hasEnabledProvider) {
      return;
    }

    var email = document.getElementById('enabled-Two_Factor_Email');
    var primaryEmail = document.querySelector('#two-factor-primary-provider option[value="Two_Factor_Email"]');

    if (email) {
      email.checked = true;
    }

    if (primaryEmail) {
      primaryEmail.disabled = false;
      primaryEmail.selected = true;
    }
  }

  function setBackupCodesCsv(codes) {
    var wrapper = document.querySelector('.two-factor-backup-codes-wrapper');

    if (wrapper) {
      wrapper.dataset.codesCsv = codes.join(',');
    }
  }

  function showError(button, message) {
    var error = document.getElementById('totp-setup-error');

    if (!error) {
      error = document.createElement('div');
      error.id = 'totp-setup-error';
      error.className = 'pkb-form-message pkb-message-error';
      error.innerHTML = '<p></p>';
      button.insertAdjacentElement('afterend', error);
    }

    error.querySelector('p').textContent = message;
  }

  function clearError() {
    var error = document.getElementById('totp-setup-error');

    if (error) {
      error.remove();
    }
  }

  function handleVerifyClick(event) {
    var button = event.target.closest('.totp-submit');
    var config = window.pkbRegistration2fa || {};
    var key = document.getElementById('two-factor-totp-key');
    var code = document.getElementById('two-factor-totp-authcode');

    if (!button || !key || !code || !config.restUrl || !config.nonce || !config.userId) {
      return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();
    clearError();
    button.disabled = true;

    window.fetch(config.restUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': config.nonce
      },
      body: JSON.stringify({
        user_id: parseInt(config.userId, 10),
        key: key.value,
        code: code.value,
        enable_provider: true
      })
    })
      .then(function (response) {
        return response.json().then(function (body) {
          if (!response.ok) {
            throw new Error(body && body.message ? body.message : config.invalidCodeMessage);
          }

          return body;
        });
      })
      .then(function (body) {
        var container = document.getElementById('two-factor-totp-options');
        var checkbox = document.getElementById('enabled-Two_Factor_Totp');

        if (checkbox) {
          checkbox.checked = true;
        }

        if (container && body && body.html) {
          container.innerHTML = body.html;
          renderQr();
        }

        var primaryOption = document.querySelector('#two-factor-primary-provider option[value="Two_Factor_Totp"]');
        if (primaryOption) {
          primaryOption.disabled = false;
          primaryOption.selected = true;
          shortenPrimaryMethodOptions();
        }
      })
      .catch(function (error) {
        showError(button, error.message || config.invalidCodeMessage);
        code.value = '';
        code.focus();
      })
      .finally(function () {
        button.disabled = false;
      });
  }

  function handleResetClick(event) {
    var button = event.target.closest('.reset-totp-key');
    var config = window.pkbRegistration2fa || {};

    if (!button || !config.restUrl || !config.nonce || !config.userId) {
      return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();
    clearError();
    button.disabled = true;

    window.fetch(config.restUrl, {
      method: 'DELETE',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': config.nonce
      },
      body: JSON.stringify({
        user_id: parseInt(config.userId, 10)
      })
    })
      .then(function (response) {
        return response.json().then(function (body) {
          if (!response.ok) {
            throw new Error(body && body.message ? body.message : config.invalidCodeMessage);
          }

          return body;
        });
      })
      .then(function (body) {
        var container = document.getElementById('two-factor-totp-options');
        var checkbox = document.getElementById('enabled-Two_Factor_Totp');
        var primaryOption = document.querySelector('#two-factor-primary-provider option[value="Two_Factor_Totp"]');

        if (checkbox) {
          checkbox.checked = false;
        }

        if (primaryOption) {
          primaryOption.disabled = true;
          primaryOption.selected = false;
          shortenPrimaryMethodOptions();
        }

        if (container && body && body.html) {
          container.innerHTML = body.html;
          renderQr();
        }

        ensureAtLeastOneProviderSelected();
      })
      .catch(function (error) {
        showError(button, error.message || config.invalidCodeMessage);
      })
      .finally(function () {
        button.disabled = false;
      });
  }

  function handleGenerateBackupCodesClick(event) {
    var button = event.target.closest('.button-two-factor-backup-codes-generate');
    var config = window.pkbRegistration2fa || {};

    if (!button || !config.backupCodesRestUrl || !config.nonce || !config.userId) {
      return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();
    clearError();
    button.disabled = true;

    window.fetch(config.backupCodesRestUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': config.nonce
      },
      body: JSON.stringify({
        user_id: parseInt(config.userId, 10),
        enable_provider: true
      })
    })
      .then(function (response) {
        return response.json().then(function (body) {
          if (!response.ok) {
            throw new Error(body && body.message ? body.message : config.invalidCodeMessage);
          }

          return body;
        });
      })
      .then(function (body) {
        var wrapper = document.querySelector('.two-factor-backup-codes-wrapper');
        var list = document.querySelector('.two-factor-backup-codes-unused-codes');
        var counter = document.querySelector('.two-factor-backup-codes-count');
        var download = document.getElementById('two-factor-backup-codes-download-link');
        var checkbox = document.getElementById('enabled-Two_Factor_Backup_Codes');
        var primaryOption = document.querySelector('#two-factor-primary-provider option[value="Two_Factor_Backup_Codes"]');

        if (checkbox) {
          checkbox.checked = true;
        }

        if (primaryOption) {
          primaryOption.disabled = false;
        }

        if (wrapper) {
          wrapper.style.display = 'block';
        }

        if (Array.isArray(body.codes) && list) {
          list.innerHTML = '';
          list.style.columnCount = '2';
          list.style.columnGap = '80px';
          list.style.maxWidth = '420px';
          body.codes.forEach(function (code) {
            var item = document.createElement('li');
            item.className = 'two-factor-backup-codes-token';
            item.textContent = code;
            list.appendChild(item);
          });
          setBackupCodesCsv(body.codes);
        }

        if (counter && body.i18n && body.i18n.count) {
          counter.textContent = body.i18n.count;
        }

        if (download && body.download_link) {
          download.setAttribute('href', body.download_link);
        }
      })
      .catch(function (error) {
        showError(button, error.message || config.invalidCodeMessage);
      })
      .finally(function () {
        button.disabled = false;
      });
  }

  function handleCopyBackupCodesClick(event) {
    var button = event.target.closest('.button-two-factor-backup-codes-copy');
    var wrapper = document.querySelector('.two-factor-backup-codes-wrapper');
    var codes = wrapper && wrapper.dataset.codesCsv ? wrapper.dataset.codesCsv : '';

    if (!button || !codes) {
      return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();

    if (window.navigator.clipboard && window.navigator.clipboard.writeText) {
      window.navigator.clipboard.writeText(codes);
      return;
    }

    var textarea = document.createElement('textarea');
    textarea.value = codes;
    textarea.style.position = 'absolute';
    textarea.style.left = '-9999px';
    document.body.appendChild(textarea);
    textarea.select();
    document.execCommand('copy');
    textarea.remove();
  }

  function init() {
    renderQr();
    shortenPrimaryMethodOptions();
    document.addEventListener('click', handleVerifyClick, true);
    document.addEventListener('click', handleResetClick, true);
    document.addEventListener('click', handleGenerateBackupCodesClick, true);
    document.addEventListener('click', handleCopyBackupCodesClick, true);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
