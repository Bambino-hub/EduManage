import { Controller } from '@hotwired/stimulus';

/*
 * Formulaire Examen Blanc : ne propose parmi les cases "Matières évaluées" que celles
 * réellement enseignées dans au moins un des niveaux cochés (chaque case matière porte
 * data-niveaux="[...]", la liste des niveaux où elle est enseignée — voir
 * ExamenBlancController::matiereNiveauxIds). Sans ce filtrage, une matière propre au lycée
 * apparaissait aussi pour un examen ne couvrant que le collège, et inversement.
 *
 * Les cases masquées sont désactivées (donc jamais soumises) mais gardent leur état "coché"
 * en mémoire dans le DOM : si l'utilisateur recoche le niveau correspondant, la sélection
 * précédente réapparaît telle quelle.
 */
export default class extends Controller {
    static targets = ['niveau', 'matiereRow', 'empty'];

    connect() {
        this.sync();
    }

    sync() {
        const niveauxCoches = new Set(
            this.niveauTargets.filter((c) => c.checked).map((c) => c.value),
        );

        let visibles = 0;
        this.matiereRowTargets.forEach((row) => {
            const niveaux = JSON.parse(row.dataset.niveaux || '[]').map(String);
            const visible = niveaux.some((id) => niveauxCoches.has(id));

            row.classList.toggle('d-none', !visible);
            const checkbox = row.querySelector('input[type="checkbox"]');
            if (checkbox) {
                checkbox.disabled = !visible;
            }
            if (visible) {
                visibles += 1;
            }
        });

        if (this.hasEmptyTarget) {
            this.emptyTarget.classList.toggle('d-none', visibles > 0);
        }
    }
}
