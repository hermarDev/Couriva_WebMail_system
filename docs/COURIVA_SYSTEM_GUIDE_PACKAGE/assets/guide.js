
(() => {
  const sidebar = document.getElementById('sidebar');
  const backdrop = document.getElementById('drawer-backdrop');
  const menuBtn = document.getElementById('menu-btn');
  function setDrawer(open) {
    sidebar.classList.toggle('open', open);
    backdrop.classList.toggle('open', open);
    if (menuBtn) menuBtn.setAttribute('aria-expanded', String(open));
  }
  if (menuBtn) menuBtn.addEventListener('click', () => setDrawer(!sidebar.classList.contains('open')));
  if (backdrop) backdrop.addEventListener('click', () => setDrawer(false));

  const tocSearch = document.getElementById('toc-search');
  const tocLinks = Array.from(document.querySelectorAll('#toc-nav a'));
  if (tocSearch) {
    tocSearch.addEventListener('input', () => {
      const q = tocSearch.value.trim().toLowerCase();
      document.querySelectorAll('#toc-nav li').forEach(li => {
        if (!q) { li.classList.remove('toc-hidden'); return; }
        li.classList.toggle('toc-hidden', !li.textContent.toLowerCase().includes(q));
      });
    });
  }
  tocLinks.forEach(a => a.addEventListener('click', () => { if (window.innerWidth <= 980) setDrawer(false); }));

  const headings = tocLinks.map(a => {
    const id = a.getAttribute('href')?.slice(1);
    const el = id ? document.getElementById(id) : null;
    return el ? { a, el } : null;
  }).filter(Boolean);
  if ('IntersectionObserver' in window && headings.length) {
    const map = new Map(headings.map(h => [h.el, h.a]));
    let activeLink = null;
    const io = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          const link = map.get(entry.target);
          if (link && link !== activeLink) {
            if (activeLink) activeLink.classList.remove('active');
            link.classList.add('active');
            activeLink = link;
          }
        }
      });
    }, { rootMargin: '-10% 0px -75% 0px', threshold: 0 });
    headings.forEach(h => io.observe(h.el));
  }

  document.querySelectorAll('.copy-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      const code = btn.closest('.code-block')?.querySelector('pre code')?.textContent || '';
      try { await navigator.clipboard.writeText(code); btn.textContent = 'Copied'; }
      catch {
        const ta = document.createElement('textarea');
        ta.value = code; document.body.appendChild(ta); ta.select();
        document.execCommand('copy'); ta.remove(); btn.textContent = 'Copied';
      }
      setTimeout(() => { btn.textContent = 'Copy'; }, 1400);
    });
  });

  const printBtn = document.getElementById('print-btn');
  if (printBtn) printBtn.addEventListener('click', () => window.print());

  const figCards = Array.from(document.querySelectorAll('.fig-card'));
  // Hydrate inline chapter figures from embedded #fig-N plates (zero duplicate base64!)
  document.querySelectorAll('img[data-fig-src]').forEach(img => {
    const num = parseInt(img.dataset.figSrc, 10);
    const plateImg = document.querySelector(`#fig-${num} img`);
    if (plateImg) img.src = plateImg.src;
  });

  const figFilter = document.getElementById('fig-filter');
  const figCount = document.getElementById('fig-count');
  if (figFilter) {
    figFilter.addEventListener('input', () => {
      const q = figFilter.value.trim().toLowerCase();
      let visible = 0;
      figCards.forEach(card => {
        const match = !q || (card.dataset.search || '').includes(q);
        card.classList.toggle('is-hidden', !match);
        if (match) visible++;
      });
      if (figCount) figCount.textContent = `${visible} / ${figCards.length}`;
    });
  }

  const lb = document.getElementById('lightbox');
  const lbImg = document.getElementById('lb-img');
  const lbStage = document.getElementById('lb-stage');
  const lbCounter = document.getElementById('lb-counter');
  const lbCaption = document.getElementById('lb-caption');
  const lbFile = document.getElementById('lb-file');
  const lbZoomLabel = document.getElementById('lb-zoom-label');
  let currentIdx = -1, zoomLevel = 0, lastTrigger = null;
  const zoomSteps = [0, 1, 1.5, 2];

  function applyZoom() {
    if (zoomLevel === 0) {
      lbStage.classList.remove('zoomed');
      lbImg.style.width = '';
      lbZoomLabel.textContent = 'Fit';
    } else {
      lbStage.classList.add('zoomed');
      lbImg.style.width = Math.round((lbImg.naturalWidth || 1200) * zoomLevel) + 'px';
      lbZoomLabel.textContent = Math.round(zoomLevel * 100) + '%';
    }
  }

  function showFigure(idx) {
    if (!figCards.length) return;
    currentIdx = (idx + figCards.length) % figCards.length;
    const card = figCards[currentIdx];
    const img = card.querySelector('img');
    const cap = card.querySelector('figcaption');
    lbImg.src = img.src;
    lbImg.alt = img.alt;
    lbCounter.textContent = `Figure ${currentIdx + 1} / ${figCards.length}`;
    lbCaption.innerHTML = cap ? cap.innerHTML : img.alt;
    lbFile.textContent = card.dataset.file || '';
    zoomLevel = 0;
    applyZoom();
    lbStage.scrollTop = 0;
    lbStage.scrollLeft = 0;
  }

  function openLightbox(idx, triggerEl) {
    lastTrigger = triggerEl || document.activeElement;
    showFigure(idx);
    lb.classList.add('open');
    lb.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    document.getElementById('lb-close').focus();
  }

  function closeLightbox() {
    lb.classList.remove('open');
    lb.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    if (lastTrigger && typeof lastTrigger.focus === 'function') lastTrigger.focus();
  }

  figCards.forEach((card, idx) => {
    card.addEventListener('click', () => openLightbox(idx, card));
    card.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openLightbox(idx, card); } });
  });
  document.querySelectorAll('.inline-fig[data-open-fig]').forEach(fig => {
    const idx = parseInt(fig.dataset.openFig, 10) - 1;
    fig.addEventListener('click', () => openLightbox(idx, fig));
    fig.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openLightbox(idx, fig); } });
  });
  document.querySelectorAll('a.fig-ref[data-open-fig]').forEach(ref => {
    ref.addEventListener('click', e => {
      e.preventDefault();
      openLightbox(parseInt(ref.dataset.openFig, 10) - 1, ref);
    });
  });

  document.getElementById('lb-close').addEventListener('click', closeLightbox);
  document.getElementById('lb-prev').addEventListener('click', () => showFigure(currentIdx - 1));
  document.getElementById('lb-next').addEventListener('click', () => showFigure(currentIdx + 1));
  document.getElementById('lb-zoom-in').addEventListener('click', () => { zoomLevel = zoomSteps[Math.min(zoomSteps.indexOf(zoomLevel) + 1, zoomSteps.length - 1)]; applyZoom(); });
  document.getElementById('lb-zoom-out').addEventListener('click', () => { zoomLevel = zoomSteps[Math.max(zoomSteps.indexOf(zoomLevel) - 1, 0)]; applyZoom(); });
  document.getElementById('lb-zoom-reset').addEventListener('click', () => { zoomLevel = zoomLevel === 0 ? 1 : 0; applyZoom(); });
  lbImg.addEventListener('click', () => { zoomLevel = zoomLevel === 0 ? 1.5 : 0; applyZoom(); });
  lbStage.addEventListener('click', e => { if (e.target === lbStage) closeLightbox(); });

  document.addEventListener('keydown', e => {
    if (!lb.classList.contains('open')) return;
    if (e.key === 'Escape') closeLightbox();
    else if (e.key === 'ArrowRight') showFigure(currentIdx + 1);
    else if (e.key === 'ArrowLeft') showFigure(currentIdx - 1);
    else if (e.key === '+' || e.key === '=') { zoomLevel = zoomSteps[Math.min(zoomSteps.indexOf(zoomLevel) + 1, zoomSteps.length - 1)]; applyZoom(); }
    else if (e.key === '-') { zoomLevel = zoomSteps[Math.max(zoomSteps.indexOf(zoomLevel) - 1, 0)]; applyZoom(); }
    else if (e.key === '0') { zoomLevel = 0; applyZoom(); }
  });

  const backTop = document.getElementById('back-top');
  window.addEventListener('scroll', () => backTop.classList.toggle('visible', window.scrollY > 500), { passive: true });
  backTop.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
})();


(() => {
  const dictInput = document.getElementById('dict-search');
  if (dictInput) {
    dictInput.addEventListener('input', () => {
      const q = dictInput.value.trim().toLowerCase();
      document.querySelectorAll('.dict-card').forEach(card => {
        card.style.display = (!q || card.textContent.toLowerCase().includes(q)) ? '' : 'none';
      });
    });
  }
})();
