import { Controller } from '@hotwired/stimulus';

/* Ajout/suppression dynamique de lignes dans une CollectionType Symfony (formulaire de reçu :
   plusieurs lignes "nature de recette / montant" sous un même reçu). */
export default class extends Controller {
    static targets = ['container', 'ligne'];
    static values = { prototype: String, index: Number };

    ajouter() {
        const html = this.prototypeValue.replace(/__name__/g, this.indexValue);
        this.indexValue += 1;

        // Fragment <table><tbody> : garantit un parsing correct du <tr> par le navigateur
        // (un <tr> seul hors <table> dans un <template> n'est pas fiable sur tous les navigateurs).
        const template = document.createElement('template');
        template.innerHTML = `<table><tbody>${html.trim()}</tbody></table>`;
        this.containerTarget.appendChild(template.content.querySelector('tr'));
    }

    retirer(event) {
        event.target.closest('[data-collection-ligne-target="ligne"]').remove();
    }
}
