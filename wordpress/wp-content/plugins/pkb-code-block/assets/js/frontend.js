(function () {
  function highlight() {
    if (!window.Prism) {
      return;
    }

    document.querySelectorAll('.pkb-code-block code[class*="language-"]').forEach(function (node) {
      window.Prism.highlightElement(node);
    });
  }

  function highlightSoon() {
    highlight();
    window.setTimeout(highlight, 80);
    window.setTimeout(highlight, 300);
  }

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }

    return new Promise(function (resolve, reject) {
      const textarea = document.createElement('textarea');
      textarea.value = text;
      textarea.setAttribute('readonly', 'readonly');
      textarea.style.position = 'fixed';
      textarea.style.left = '-9999px';
      document.body.appendChild(textarea);
      textarea.select();

      try {
        document.execCommand('copy') ? resolve() : reject(new Error('Copy failed'));
      } catch (error) {
        reject(error);
      } finally {
        document.body.removeChild(textarea);
      }
    });
  }

  function bindCopyButtons() {
    document.querySelectorAll('.pkb-code-copy-button').forEach(function (button) {
      if (button.dataset.pkbBound === '1') {
        return;
      }

      button.dataset.pkbBound = '1';
      button.addEventListener('click', function () {
        const block = button.closest('.pkb-code-block');
        const code = block ? block.querySelector('code') : null;
        if (!code) {
          return;
        }

        const original = button.textContent;
        copyText(code.textContent || '').then(function () {
          button.textContent = 'Copied';
          window.setTimeout(function () {
            button.textContent = original;
          }, 1300);
        }).catch(function () {
          button.textContent = 'Copy failed';
          window.setTimeout(function () {
            button.textContent = original;
          }, 1300);
        });
      });
    });
  }

  function init() {
    highlightSoon();
    bindCopyButtons();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
