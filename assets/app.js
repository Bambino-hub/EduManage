import './stimulus_bootstrap.js';

/*
 * Point d'entrée JavaScript de l'application (chargé via importmap() dans base.html.twig).
 *
 * Ordre important :
 *   1. Le CSS de Bootstrap d'abord (styles de base)
 *   2. Notre CSS ensuite, pour pouvoir surcharger Bootstrap
 *   3. Le JS de Bootstrap (dropdowns, modals, tooltips...)
 */
import 'bootstrap/dist/css/bootstrap.min.css';
import './styles/app.css';

import 'bootstrap';
