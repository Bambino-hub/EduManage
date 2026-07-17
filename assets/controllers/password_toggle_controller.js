import { Controller } from '@hotwired/stimulus';

/* Bouton "œil" à côté d'un champ mot de passe : bascule entre masqué et affiché en clair. */
export default class extends Controller {
    static targets = ['input', 'icon'];

    toggle() {
        const showing = this.inputTarget.type === 'text';
        this.inputTarget.type = showing ? 'password' : 'text';
        this.iconTarget.classList.toggle('bi-eye-slash', showing);
        this.iconTarget.classList.toggle('bi-eye', !showing);
    }
}
