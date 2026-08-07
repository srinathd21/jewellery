const body = document.body;
const sidebarToggle = document.getElementById("sidebarToggle");
const themeToggle = document.getElementById("themeToggle");
const themeIcon = themeToggle ? themeToggle.querySelector("i") : null;
const backdrop = document.getElementById("mobileBackdrop");

function updateThemeIcon() {
  if (!themeIcon) return;
  themeIcon.className = body.classList.contains("dark-mode")
    ? "fa-regular fa-sun"
    : "fa-regular fa-moon";
}

function applySaved() {
  if (localStorage.getItem("jewellery-theme") === "dark") {
    body.classList.add("dark-mode");
  }
  if (
    localStorage.getItem("jewellery-sidebar") === "collapsed" &&
    window.innerWidth >= 992
  ) {
    body.classList.add("sidebar-collapsed");
  }
  updateThemeIcon();
}

function closeMobileSidebar() {
  body.classList.remove("sidebar-open");
  if (!backdrop) return;
  backdrop.style.display = "none";
  backdrop.classList.remove("show");
}

if (sidebarToggle) {
  sidebarToggle.addEventListener("click", () => {
    if (window.innerWidth < 992) {
      const open = body.classList.toggle("sidebar-open");
      if (backdrop) {
        backdrop.style.display = open ? "block" : "none";
        requestAnimationFrame(() => backdrop.classList.toggle("show", open));
      }
    } else {
      body.classList.toggle("sidebar-collapsed");
      localStorage.setItem(
        "jewellery-sidebar",
        body.classList.contains("sidebar-collapsed") ? "collapsed" : "expanded"
      );
    }
  });
}

if (themeToggle) {
  themeToggle.addEventListener("click", () => {
    body.classList.toggle("dark-mode");
    localStorage.setItem(
      "jewellery-theme",
      body.classList.contains("dark-mode") ? "dark" : "light"
    );
    updateThemeIcon();
  });
}

if (backdrop) backdrop.addEventListener("click", closeMobileSidebar);
window.addEventListener("resize", () => {
  if (window.innerWidth >= 992) closeMobileSidebar();
});
document.addEventListener("keydown", (event) => {
  if (event.key === "Escape") closeMobileSidebar();
});

function forceSubmenuState(toggle, submenu, open) {
  toggle.setAttribute("aria-expanded", open ? "true" : "false");
  toggle.classList.toggle("submenu-open", open);
  submenu.classList.toggle("is-open", open);

  if (open) {
    submenu.style.setProperty("display", "block", "important");
    submenu.style.setProperty("visibility", "visible", "important");
    submenu.style.setProperty("opacity", "1", "important");
    submenu.style.setProperty("height", "auto", "important");
    submenu.style.setProperty("max-height", "none", "important");
    submenu.style.setProperty("overflow", "visible", "important");
  } else {
    submenu.style.setProperty("display", "none", "important");
    submenu.style.setProperty("visibility", "hidden", "important");
    submenu.style.setProperty("opacity", "0", "important");
    submenu.style.setProperty("height", "0", "important");
    submenu.style.setProperty("max-height", "0", "important");
    submenu.style.setProperty("overflow", "hidden", "important");
  }
}

function initSidebarSubmenus() {
  const sidebar = document.getElementById("sidebar");
  if (!sidebar) return;

  const toggles = Array.from(
    sidebar.querySelectorAll(".submenu-toggle[data-submenu-target]")
  );

  toggles.forEach((toggle) => {
    const id = toggle.getAttribute("data-submenu-target");
    const submenu = id ? document.getElementById(id) : null;
    if (!submenu) return;
    const open = toggle.getAttribute("aria-expanded") === "true";
    forceSubmenuState(toggle, submenu, open);
  });

  sidebar.addEventListener("click", (event) => {
    const toggle = event.target.closest(".submenu-toggle[data-submenu-target]");
    if (!toggle || !sidebar.contains(toggle)) return;

    event.preventDefault();
    event.stopImmediatePropagation();

    const id = toggle.getAttribute("data-submenu-target");
    const submenu = id ? document.getElementById(id) : null;
    if (!submenu) return;

    const open = toggle.getAttribute("aria-expanded") !== "true";

    toggles.forEach((otherToggle) => {
      if (otherToggle === toggle) return;
      const otherId = otherToggle.getAttribute("data-submenu-target");
      const otherMenu = otherId ? document.getElementById(otherId) : null;
      if (otherMenu) forceSubmenuState(otherToggle, otherMenu, false);
    });

    forceSubmenuState(toggle, submenu, open);
  }, true);
}

applySaved();
if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", initSidebarSubmenus, { once: true });
} else {
  initSidebarSubmenus();
}
