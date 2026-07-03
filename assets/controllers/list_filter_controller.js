import { Controller } from '@hotwired/stimulus';

/*
 * Filtre une liste de lignes de tableau côté client : une recherche texte
 * (comparée à un haystack précalculé par ligne) combinée à des filtres
 * optionnels par attribut (data-<clé>="valeur" sur la ligne).
 * Générique : réutilisable sur n'importe quelle page listant beaucoup
 * d'éléments côté admin (enseignants, classes, matières...).
 */
export default class extends Controller {
    static targets = ['row', 'search', 'empty', 'count'];
    static values = { total: Number };

    connect() {
        this.filters = {};
        this.apply();
    }

    search() {
        this.filters.q = this.searchTarget.value.trim().toLowerCase();
        this.apply();
    }

    filter(event) {
        const { filterKey } = event.params;
        this.filters[filterKey] = event.target.value;
        this.apply();
    }

    apply() {
        let visible = 0;

        this.rowTargets.forEach((row) => {
            const matchesSearch = !this.filters.q || row.dataset.search.includes(this.filters.q);
            const matchesFilters = Object.entries(this.filters).every(([key, value]) => {
                if (key === 'q' || !value) {
                    return true;
                }
                return row.dataset[key] === value;
            });

            const show = matchesSearch && matchesFilters;
            row.hidden = !show;
            if (show) {
                visible++;
            }
        });

        if (this.hasCountTarget) {
            this.countTarget.textContent = visible === this.totalValue
                ? `${visible} enseignant${visible > 1 ? 's' : ''}`
                : `${visible} / ${this.totalValue} enseignant${this.totalValue > 1 ? 's' : ''}`;
        }

        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = visible !== 0;
        }
    }
}
