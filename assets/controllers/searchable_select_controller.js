import { Controller } from '@hotwired/stimulus';

/*
 * Ajoute une recherche rapide au-dessus d'un <select> natif, sans dépendance
 * externe : un champ texte filtre une liste déroulante d'options, le <select>
 * d'origine reste la source de vérité soumise avec le formulaire. Générique :
 * réutilisable sur n'importe quel select avec beaucoup d'options.
 */
export default class extends Controller {
    static targets = ['select', 'input', 'menu'];

    connect() {
        this.options = Array.from(this.selectTarget.options)
            .filter((option) => option.value !== '')
            .map((option) => ({ value: option.value, label: option.textContent.trim() }));

        this.selectTarget.classList.add('visually-hidden');
        this.selectTarget.setAttribute('tabindex', '-1');
        this.selectTarget.setAttribute('aria-hidden', 'true');

        this.syncInputFromSelect();
        this.renderMenu(this.options);
    }

    syncInputFromSelect() {
        const selected = this.selectTarget.options[this.selectTarget.selectedIndex];
        this.inputTarget.value = selected && selected.value !== '' ? selected.textContent.trim() : '';
    }

    open() {
        this.menuTarget.classList.add('show');
    }

    close() {
        this.menuTarget.classList.remove('show');
    }

    closeSoon() {
        // Laisse le temps au mousedown sur une option de s'exécuter avant de fermer.
        setTimeout(() => this.close(), 150);
    }

    filter() {
        const q = this.inputTarget.value.trim().toLowerCase();
        const filtered = q === ''
            ? this.options
            : this.options.filter((option) => option.label.toLowerCase().includes(q));
        this.renderMenu(filtered);
        this.open();
    }

    renderMenu(options) {
        this.menuTarget.innerHTML = '';

        if (options.length === 0) {
            const li = document.createElement('li');
            li.className = 'px-3 py-2 text-muted small';
            li.textContent = 'Aucun résultat';
            this.menuTarget.appendChild(li);
            return;
        }

        options.forEach((option) => {
            const li = document.createElement('li');
            li.className = 'dropdown-item';
            li.style.cursor = 'pointer';
            li.textContent = option.label;
            li.dataset.value = option.value;
            li.addEventListener('mousedown', (event) => {
                event.preventDefault();
                this.choose(option);
            });
            this.menuTarget.appendChild(li);
        });
    }

    choose(option) {
        this.selectTarget.value = option.value;
        this.selectTarget.dispatchEvent(new Event('change', { bubbles: true }));
        this.inputTarget.value = option.label;
        this.close();
    }

    keydown(event) {
        if (event.key === 'Escape') {
            this.close();
            return;
        }

        if (event.key === 'Enter') {
            event.preventDefault();
            const first = this.menuTarget.querySelector('.dropdown-item[data-value]');
            if (first) {
                this.choose({ value: first.dataset.value, label: first.textContent });
            }
        }
    }
}
