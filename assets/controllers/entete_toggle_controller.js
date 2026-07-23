import { Controller } from '@hotwired/stimulus';

/*
 * Bascule l'en-tête du collège (logo, adresse, devise) sur les liens d'aperçu/téléchargement
 * PDF — bulletins, fiches de notes, emplois du temps, tableaux d'examens/surveillance,
 * listes élèves/enseignants... Réutilisable partout : ne fait que réécrire le paramètre
 * ?entete_college=0|1 des liens ciblés, coché ou décoché selon l'état initial de la case.
 * Nommé `entete_college` (et pas juste `entete`) pour ne pas entrer en conflit avec le
 * paramètre `entete` déjà utilisé ailleurs (sous-titre libre des tableaux d'examens/
 * surveillance). Le rendu conditionnel de l'en-tête se fait côté serveur (chaque contrôleur
 * lit ?entete_college et passe `avecEntete` au template PDF correspondant).
 */
export default class extends Controller {
    static targets = ['checkbox', 'link'];

    connect() {
        this.update();
    }

    update() {
        const avecEntete = this.checkboxTarget.checked ? '1' : '0';

        this.linkTargets.forEach((link) => {
            const url = new URL(link.dataset.baseHref, window.location.origin);
            url.searchParams.set('entete_college', avecEntete);
            link.href = url.pathname + url.search;
        });
    }
}
