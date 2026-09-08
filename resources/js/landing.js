const menuButton = document.querySelector('[data-menu-button]');
const navigation = document.querySelector('[data-navigation]');
const searchToggle = document.querySelector('[data-search-toggle]');
const searchForm = document.querySelector('[data-search-form]');
const searchClose = document.querySelector('[data-search-close]');

menuButton?.addEventListener('click', () => {
    const isOpen = navigation.classList.toggle('is-open');
    menuButton.setAttribute('aria-expanded', String(isOpen));
    menuButton.querySelector('.material-symbols-outlined').textContent = isOpen ? 'close' : 'menu';
});

navigation?.addEventListener('click', event => {
    if (!event.target.closest('a')) return;
    navigation.classList.remove('is-open');
    menuButton?.setAttribute('aria-expanded', 'false');
});

function setSearch(open) {
    if (!searchForm) return;
    searchForm.hidden = !open;
    if (open) searchForm.querySelector('input')?.focus();
    else searchToggle?.focus();
}

searchToggle?.addEventListener('click', () => setSearch(true));
searchClose?.addEventListener('click', () => setSearch(false));
document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && searchForm && !searchForm.hidden) setSearch(false);
});
