import { Controller } from '@hotwired/stimulus';

/*
 * Bascule l'affichage des cases de la vue globale EDT entre "code matière seul" et
 * "matière + nom de l'enseignant" (sans prénom), et répercute le choix sur le lien
 * d'export PDF via ?affichage=matiere|enseignant.
 *
 * Les deux variantes de texte sont déjà présentes dans le DOM (rendu serveur), cette
 * bascule ne fait que jouer sur une classe CSS — pas de réécriture de textContent, qui
 * casserait le glisser-déposer d'edt-globale-controller (les <span> d'une case sont
 * reparentés tels quels vers une autre case, avec tout leur contenu).
 */
export default class extends Controller {
    static targets = ['checkbox', 'table', 'link'];

    connect() {
        this.update();
    }

    update() {
        const detaille = this.checkboxTarget.checked;

        this.tableTargets.forEach((t) => t.classList.toggle('edt-affichage-enseignant', detaille));

        this.linkTargets.forEach((link) => {
            const url = new URL(link.href || link.dataset.baseHref, window.location.origin);
            url.searchParams.set('affichage', detaille ? 'enseignant' : 'matiere');
            link.href = url.pathname + url.search;
        });
    }
}
