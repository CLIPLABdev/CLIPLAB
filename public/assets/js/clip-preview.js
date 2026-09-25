(() => {
  // Troca a capa do corte por um player com o próprio arquivo do corte.
  const safePath = /^\/clips\/[1-9][0-9]*\/preview$/;
  let current = null;

  const stop = () => {
    if (!current) return;
    current.video.pause();
    current.video.remove();
    current.media.classList.remove('is-playing');
    current.button.hidden = false;
    current = null;
  };

  document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-clip-preview]') : null;
    if (!button) return;
    const src = button.getAttribute('data-clip-preview') || '';
    const media = button.closest('[data-clip-media]');
    if (!media || !safePath.test(src)) return;
    event.preventDefault();
    stop();

    const video = document.createElement('video');
    video.src = src;
    video.controls = true;
    video.autoplay = true;
    video.playsInline = true;
    video.preload = 'metadata';
    video.setAttribute('aria-label', button.getAttribute('aria-label') || 'Prévia do corte');
    video.addEventListener('ended', stop);
    media.appendChild(video);
    media.classList.add('is-playing');
    button.hidden = true;
    current = { video, media, button };
    video.focus({ preventScroll: true });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') stop();
  });
})();
