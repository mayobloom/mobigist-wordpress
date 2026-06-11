(function () {
  document.addEventListener('DOMContentLoaded', function () {
    let backToTop = document.querySelector('.pkb-back-to-top');
    if (!backToTop) {
      backToTop = document.createElement('button');
      backToTop.type = 'button';
      backToTop.className = 'pkb-back-to-top';
      backToTop.setAttribute('aria-label', '페이지 맨 위로 이동');
      backToTop.innerHTML = '<svg aria-hidden="true" viewBox="0 0 24 24" focusable="false"><path d="M6 14l6-6 6 6"></path></svg>';
      document.body.appendChild(backToTop);
    }

    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    backToTop.addEventListener('click', function () {
      window.scrollTo({
        top: 0,
        behavior: prefersReducedMotion.matches ? 'auto' : 'smooth'
      });
    });
  });
})();
