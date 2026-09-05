// ============================================================
// DHA DMS — Global App JavaScript v3.0
// ============================================================

// ── Toast Notifications ──────────────────────────────────────
// Defined at the top level (not inside DOMContentLoaded below) so it's
// available the instant this script finishes loading. Several pages
// (e.g. validate.php) call showToast() from their own DOMContentLoaded
// handler; since app.js is loaded at the very bottom of the page via
// footer.php, those handlers can register — and fire — before this one
// does, if showToast were only assigned once DOMContentLoaded fires here
// too. That race made showToast silently undefined at the moment it was
// needed, throwing and aborting whatever call it, with no visible error.
// document.getElementById() inside the function body still only runs when
// showToast is actually called, by which point the DOM is always ready in
// practice, so hoisting the definition itself is safe.
window.showToast = function (message, type = 'success', duration = 4000) {
  const container = document.getElementById('toast-container');
  if (!container) return;

  const icons  = { success: 'fa-check-circle', error: 'fa-times-circle', warning: 'fa-exclamation-triangle', info: 'fa-info-circle' };
  const colors = { success: '#22c55e', error: '#ef4444', warning: '#f59e0b', info: '#3b82f6' };

  const toast = document.createElement('div');
  toast.className = `toast-msg ${type}`;
  toast.innerHTML = `
    <div class="d-flex align-items-center gap-2">
      <i class="fas ${icons[type] || 'fa-info-circle'}" style="color:${colors[type]};font-size:1.05rem;flex-shrink:0;"></i>
      <span style="flex:1;">${message}</span>
      <button onclick="this.closest('.toast-msg').remove()" style="margin-left:auto;background:none;border:none;color:var(--text-muted);cursor:pointer;padding:2px;">
        <i class="fas fa-times"></i>
      </button>
    </div>`;
  container.appendChild(toast);

  // Auto-dismiss with fade
  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transform = 'translateX(110%)';
    toast.style.transition = 'all .3s ease';
    setTimeout(() => toast.remove(), 320);
  }, duration);
};

document.addEventListener('DOMContentLoaded', function () {

  // ── Sidebar Toggle ─────────────────────────────────────────
  const sidebar     = document.getElementById('sidebar');
  const topbar      = document.getElementById('topbar');
  const mainContent = document.getElementById('mainContent');
  const toggleBtn   = document.getElementById('sidebarToggle');
  const overlay     = document.getElementById('sidebarOverlay');

  function isMobile() { return window.innerWidth <= 991; }

  // Restore sidebar state (desktop only)
  const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
  if (sidebarCollapsed && !isMobile()) {
    sidebar?.classList.add('collapsed');
    topbar?.classList.add('collapsed');
    mainContent?.classList.add('collapsed');
  }

  function openMobileSidebar() {
    sidebar?.classList.add('mobile-open');
    overlay?.classList.add('active');
    document.body.style.overflow = 'hidden';
    toggleBtn?.setAttribute('aria-expanded', 'true');
  }
  function closeMobileSidebar() {
    sidebar?.classList.remove('mobile-open');
    overlay?.classList.remove('active');
    document.body.style.overflow = '';
    toggleBtn?.setAttribute('aria-expanded', 'false');
  }

  toggleBtn?.addEventListener('click', function () {
    if (isMobile()) {
      if (sidebar?.classList.contains('mobile-open')) {
        closeMobileSidebar();
      } else {
        openMobileSidebar();
      }
    } else {
      const isCollapsed = sidebar?.classList.toggle('collapsed');
      topbar?.classList.toggle('collapsed');
      mainContent?.classList.toggle('collapsed');
      localStorage.setItem('sidebarCollapsed', isCollapsed);
    }
  });

  overlay?.addEventListener('click', closeMobileSidebar);

  // Close sidebar on window resize to desktop
  window.addEventListener('resize', () => {
    if (!isMobile()) {
      closeMobileSidebar();
    }
  });

  // Close sidebar on nav link click (mobile)
  sidebar?.querySelectorAll('.sidebar-link').forEach(link => {
    link.addEventListener('click', () => {
      if (isMobile()) closeMobileSidebar();
    });
  });

  // ── Dark/Light Mode ────────────────────────────────────────
  const themeBtn  = document.getElementById('themeToggle');
  const themeIcon = document.getElementById('themeIcon');
  const html      = document.documentElement;

  const savedTheme = localStorage.getItem('dha-theme') || 'dark';
  html.setAttribute('data-theme', savedTheme);
  updateThemeIcon(savedTheme);

  themeBtn?.addEventListener('click', function () {
    const current = html.getAttribute('data-theme');
    const next    = current === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    localStorage.setItem('dha-theme', next);
    updateThemeIcon(next);
    showToast(`Switched to ${next} mode`, 'info', 2500);
  });

  function updateThemeIcon(theme) {
    if (!themeIcon) return;
    themeIcon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
  }

  // ── Auto-dismiss alerts ────────────────────────────────────
  document.querySelectorAll('.alert-auto-dismiss').forEach(alert => {
    setTimeout(() => {
      alert.style.transition = 'opacity .4s ease';
      alert.style.opacity = '0';
      setTimeout(() => alert.remove(), 400);
    }, 5000);
  });

  // ── Global Search ──────────────────────────────────────────
  const searchInput   = document.getElementById('globalSearch');
  const searchResults = document.getElementById('searchResults');

  searchInput?.addEventListener('input', debounce(function () {
    const query = this.value.trim();
    if (query.length < 2) {
      searchResults?.classList.add('d-none');
      return;
    }

    fetch(`${getBasePath()}admin/ajax/search.php?q=${encodeURIComponent(query)}`)
      .then(r => r.json())
      .then(data => {
        if (!data.results?.length) {
          searchResults.innerHTML = `
            <div class="p-3 text-center" style="color:var(--text-muted);font-size:.84rem;">
              <i class="fas fa-search-minus mb-2 d-block" style="font-size:1.3rem;opacity:.4;"></i>
              No results found for "<strong>${query}</strong>"
            </div>`;
        } else {
          searchResults.innerHTML = data.results.map(r =>
            `<a href="${r.url}" class="dropdown-item">
               <i class="fas ${r.icon} text-dha"></i>
               <div>
                 <strong>${r.title}</strong>
                 <small class="d-block" style="color:var(--text-muted);">${r.subtitle}</small>
               </div>
             </a>`
          ).join('') + `<div class="px-4 pb-2" style="font-size:.72rem;color:var(--text-muted);">${data.results.length} result(s)</div>`;
        }
        searchResults?.classList.remove('d-none');
      })
      .catch(() => {});
  }, 300));

  document.addEventListener('click', function (e) {
    if (!searchInput?.contains(e.target) && !searchResults?.contains(e.target)) {
      searchResults?.classList.add('d-none');
    }
  });

  searchInput?.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      searchResults?.classList.add('d-none');
      this.blur();
    }
  });

  // ── Confirm Delete ─────────────────────────────────────────
  document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('click', function (e) {
      if (!confirm(this.dataset.confirm || 'Are you sure?')) {
        e.preventDefault();
      }
    });
  });

  // ── Table Sort ─────────────────────────────────────────────
  document.querySelectorAll('[data-sortable]').forEach(header => {
    header.style.cursor = 'pointer';
    header.addEventListener('click', function () {
      const table = this.closest('table');
      const tbody = table.querySelector('tbody');
      const rows  = Array.from(tbody.querySelectorAll('tr'));
      const col   = Array.from(this.parentNode.children).indexOf(this);
      const asc   = this.dataset.sortDir !== 'asc';
      this.dataset.sortDir = asc ? 'asc' : 'desc';

      // Update sort arrow
      this.parentNode.querySelectorAll('th').forEach(th => {
        th.innerHTML = th.innerHTML.replace(/ <i class="fas fa-sort.*?"><\/i>/, '');
      });
      this.innerHTML += ` <i class="fas fa-sort-${asc ? 'up' : 'down'}"></i>`;

      rows.sort((a, b) => {
        const aText = a.cells[col]?.textContent.trim() ?? '';
        const bText = b.cells[col]?.textContent.trim() ?? '';
        return asc ? aText.localeCompare(bText, undefined, {numeric: true})
                   : bText.localeCompare(aText, undefined, {numeric: true});
      });
      rows.forEach(row => tbody.appendChild(row));
    });
  });


  // ── Auto-generate Username & Initials ───────────────────────
  let usernameEdited = false;
  let initialsEdited = false;

  const usernameInput = document.getElementById('username_field');
  const usernameHidden = document.getElementById('username_hidden');
  const initialsInput = document.getElementById('initials_field');
  const fullNameInput = document.getElementById('full_name_field');
  const userForm = document.getElementById('userForm');

  if (usernameInput) {
    usernameInput.addEventListener('input', () => {
      usernameEdited = true;
    });
  }
  if (initialsInput) {
    initialsInput.addEventListener('input', () => {
      initialsEdited = true;
    });
  }
  if (userForm) {
    userForm.addEventListener('reset', () => {
      usernameEdited = false;
      initialsEdited = false;
    });
  }

  function autoFillUserFields() {
    const action = document.getElementById('formAction')?.value ?? 'create';
    if (action !== 'create') return;
    const fullName = fullNameInput?.value.trim() ?? '';
    if (fullName) {
      if (usernameInput && !usernameEdited) {
        const generatedUser = fullName.toLowerCase().replace(/[^a-z0-9]/g, '_');
        usernameInput.value = generatedUser;
        if (usernameHidden) usernameHidden.value = generatedUser;
      }
      if (initialsInput && !initialsEdited) {
        const parts = fullName.split(' ').filter(Boolean);
        initialsInput.value = parts.length >= 2
          ? (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
          : fullName.substring(0, 2).toUpperCase();
      }
    } else {
      if (usernameInput && !usernameEdited) {
        usernameInput.value = '';
        if (usernameHidden) usernameHidden.value = '';
      }
      if (initialsInput && !initialsEdited) {
        initialsInput.value = '';
      }
    }
  }
  fullNameInput?.addEventListener('input', autoFillUserFields);

  // ── Number Counter Animation ────────────────────────────────
  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (!entry.isIntersecting) return;
      const el     = entry.target;
      const target = parseInt(el.textContent) || 0;
      if (target === 0) return;
      let current  = 0;
      const step   = Math.max(1, Math.ceil(target / 36));
      const timer  = setInterval(() => {
        current = Math.min(current + step, target);
        el.textContent = current.toLocaleString();
        if (current >= target) clearInterval(timer);
      }, 35);
      observer.unobserve(el);
    });
  }, { threshold: 0.2 });

  document.querySelectorAll('.card-value').forEach(el => observer.observe(el));

  // ── Stat Card Tilt Effect ───────────────────────────────────
  document.querySelectorAll('.stat-card').forEach(card => {
    card.addEventListener('mousemove', function (e) {
      const rect = this.getBoundingClientRect();
      const x = ((e.clientX - rect.left) / rect.width - 0.5) * 10;
      const y = ((e.clientY - rect.top) / rect.height - 0.5) * -8;
      this.style.transform = `perspective(600px) rotateY(${x}deg) rotateX(${y}deg) translateY(-7px)`;
    });
    card.addEventListener('mouseleave', function () {
      this.style.transform = '';
    });
  });

  // ── Utility: Debounce ──────────────────────────────────────
  function debounce(fn, delay) {
    let t;
    return function (...args) {
      clearTimeout(t);
      t = setTimeout(() => fn.apply(this, args), delay);
    };
  }

  // ── Get Base Path ──────────────────────────────────────────
  function getBasePath() {
    return window.location.pathname.includes('/pages/') ? '../' : '';
  }

  // ── Keyboard shortcut: / to focus search ──────────────────
  document.addEventListener('keydown', function (e) {
    if (e.key === '/' && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA') {
      e.preventDefault();
      searchInput?.focus();
    }
  });

  // ── Mark page active in sidebar ───────────────────────────
  // (already done server-side but ensure correct on client)

});

// ── Pagination (Globally Available before DOMContentLoaded) ──
window.initPagination = function (tableId, perPage = 10) {
  const table = document.getElementById(tableId);
  if (!table) return;
  const tbody = table.querySelector('tbody');
  const rows  = Array.from(tbody.querySelectorAll('tr'));
  const total = Math.ceil(rows.length / perPage);
  let current = 1;

  function showPage(page) {
    current = page;
    const start = (page - 1) * perPage;
    rows.forEach((r, i) => r.style.display = (i >= start && i < start + perPage) ? '' : 'none');
    renderPager();
  }

  function renderPager() {
    let pager = document.getElementById(tableId + '-pager');
    if (!pager) {
      pager = document.createElement('div');
      pager.id = tableId + '-pager';
      pager.className = 'd-flex align-items-center justify-content-between mt-3 flex-wrap gap-2';
      table.parentElement.appendChild(pager);
    }
    const showing = Math.min(current * perPage, rows.length);
    const from    = (current - 1) * perPage + 1;
    let html = `<div style="font-size:.8rem;color:var(--text-muted);">Showing ${from}–${showing} of ${rows.length}</div>`;
    html += '<ul class="pagination pagination-sm mb-0 gap-1">';
    html += `<li class="page-item ${current===1?'disabled':''}"><a class="page-link" href="#" data-page="${current-1}"><i class="fas fa-chevron-left" style="font-size:.7rem;"></i></a></li>`;
    for (let i = 1; i <= total; i++) {
      if (total > 7 && i > 2 && i < total - 1 && Math.abs(i - current) > 1) {
        if (i === 3 || i === total - 2) html += `<li class="page-item disabled"><a class="page-link">…</a></li>`;
        continue;
      }
      html += `<li class="page-item ${i===current?'active':''}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
    }
    html += `<li class="page-item ${current===total?'disabled':''}"><a class="page-link" href="#" data-page="${current+1}"><i class="fas fa-chevron-right" style="font-size:.7rem;"></i></a></li>`;
    html += '</ul>';
    pager.innerHTML = html;
    pager.querySelectorAll('[data-page]').forEach(link => {
      link.addEventListener('click', function (e) {
        e.preventDefault();
        const p = parseInt(this.dataset.page);
        if (p >= 1 && p <= total) showPage(p);
      });
    });
  }

  if (total > 1) showPage(1);
};
