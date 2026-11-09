/*
 * Réglages de lisibilité du tableau de bord.
 * Chargé dans <head> pour appliquer les préférences avant l'affichage (pas de clignotement).
 * Les préférences restent dans le navigateur (localStorage) : rien n'est envoyé au serveur.
 */
(function () {
    var KEY = 'jobs:lisibilite';
    var FLAGS = ['contraste', 'opaque', 'calme', 'police', 'interligne', 'liens'];
    var SIZES = ['90', '100', '115', '130'];
    var DEFAULTS = { texte: '100', contraste: false, opaque: false, calme: false, police: false, interligne: false, liens: false };

    function read() {
        var prefs = Object.assign({}, DEFAULTS);

        try {
            Object.assign(prefs, JSON.parse(localStorage.getItem(KEY) || '{}'));
        } catch (e) {}

        if (SIZES.indexOf(String(prefs.texte)) === -1) {
            prefs.texte = '100';
        }

        return prefs;
    }

    function apply(prefs) {
        var html = document.documentElement;
        html.setAttribute('data-texte', prefs.texte);
        FLAGS.forEach(function (flag) {
            html.classList.toggle('jobs-' + flag, !!prefs[flag]);
        });
    }

    function save(prefs) {
        try {
            localStorage.setItem(KEY, JSON.stringify(prefs));
        } catch (e) {}
        apply(prefs);
    }

    function themeMode() {
        try {
            return localStorage.getItem('filament:theme:mode') || 'system';
        } catch (e) {
            return 'system';
        }
    }

    apply(read());

    document.addEventListener('alpine:init', function () {
        window.Alpine.data('jobsLisibilite', function () {
            return {
                open: false,
                prefs: read(),
                mode: themeMode(),
                sizes: [
                    { value: '90', label: 'A−', name: 'Texte réduit (90 %)' },
                    { value: '100', label: '100 %', name: 'Texte normal' },
                    { value: '115', label: '115 %', name: 'Texte agrandi (115 %)' },
                    { value: '130', label: '130 %', name: 'Texte très agrandi (130 %)' },
                ],
                modes: [
                    { value: 'light', label: 'Clair' },
                    { value: 'dark', label: 'Sombre' },
                    { value: 'system', label: 'Auto' },
                ],
                options: [
                    { key: 'contraste', label: 'Contraste renforcé', hint: 'Textes et bordures plus marqués' },
                    { key: 'opaque', label: 'Réduire la transparence', hint: 'Surfaces pleines au lieu du verre' },
                    { key: 'calme', label: 'Réduire les animations', hint: 'Fondus simples, sans mouvement' },
                    { key: 'police', label: 'Police haute lisibilité', hint: 'Atkinson Hyperlegible' },
                    { key: 'interligne', label: 'Interligne aéré', hint: 'Plus d’espace entre les lignes' },
                    { key: 'liens', label: 'Souligner les liens', hint: 'Repérer les liens sans la couleur' },
                ],
                setSize: function (value) {
                    this.prefs.texte = value;
                    save(this.prefs);
                },
                toggle: function (key) {
                    this.prefs[key] = !this.prefs[key];
                    save(this.prefs);
                },
                setMode: function (value) {
                    this.mode = value;
                    window.dispatchEvent(new CustomEvent('theme-changed', { detail: value }));
                },
                reset: function () {
                    this.prefs = Object.assign({}, DEFAULTS);
                    save(this.prefs);
                    this.setMode('system');
                },
                shortcut: function (event) {
                    if (event.altKey && event.code === 'KeyL') {
                        event.preventDefault();
                        this.open = !this.open;
                    }
                },
            };
        });
    });
})();
