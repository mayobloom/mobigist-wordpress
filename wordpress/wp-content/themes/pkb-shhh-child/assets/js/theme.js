(function () {
  document.addEventListener('DOMContentLoaded', function () {
    const title = document.querySelector('.wp-block-site-title');
    if (title && !document.querySelector('.pkb-title-about-link')) {
      const originalSeparator = title.nextElementSibling;
      if (originalSeparator && originalSeparator.textContent.trim() === '•') {
        originalSeparator.classList.add('pkb-hidden-theme-separator');
      }

      const separator = document.createElement('span');
      separator.className = 'pkb-title-separator';
      separator.textContent = '•';

      const about = document.createElement('a');
      about.className = 'pkb-title-about-link';
      about.href = '/category/about/';
      about.textContent = 'About';

      title.insertAdjacentElement('afterend', about);
      title.insertAdjacentElement('afterend', separator);
    }
  });
})();
