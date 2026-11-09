{{-- Bouton et panneau « Lisibilité » de la barre du haut (raccourci : Option/Alt + L). --}}
<div
    x-data="jobsLisibilite"
    x-on:keydown.window="shortcut($event)"
    x-on:keydown.escape.window="open = false"
    class="jobs-lisibilite"
>
    <button
        type="button"
        class="jobs-icon-btn"
        x-on:click="open = ! open"
        x-bind:class="{ 'jobs-on': open }"
        x-bind:aria-expanded="open ? 'true' : 'false'"
        aria-controls="jobs-lisibilite-panneau"
        aria-label="Lisibilité"
        title="Lisibilité (⌥ L)"
    >
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="4.5" r="1.6"/><path d="M5 8.5h14M12 8.5v5.5m0 0-3 7m3-7 3 7"/></svg>
    </button>

    <section
        id="jobs-lisibilite-panneau"
        class="jobs-panneau"
        role="dialog"
        aria-label="Réglages de lisibilité"
        x-cloak
        x-show="open"
        x-on:click.outside="open = false"
        x-transition:enter="jobs-materialize-enter"
        x-transition:enter-start="jobs-materialize-from"
        x-transition:enter-end="jobs-materialize-to"
        x-transition:leave="jobs-materialize-leave"
        x-transition:leave-start="jobs-materialize-to"
        x-transition:leave-end="jobs-materialize-from"
    >
        <div class="jobs-panneau-head">
            <h2>Lisibilité</h2>
            <button type="button" class="jobs-link-btn" x-on:click="reset()">Réinitialiser</button>
        </div>

        <p class="jobs-panneau-label" id="jobs-l-apparence">Apparence</p>
        <div class="jobs-seg" role="group" aria-labelledby="jobs-l-apparence">
            <template x-for="m in modes" x-bind:key="m.value">
                <button type="button" x-on:click="setMode(m.value)" x-bind:class="{ 'jobs-on': mode === m.value }" x-bind:aria-pressed="mode === m.value ? 'true' : 'false'" x-text="m.label"></button>
            </template>
        </div>

        <p class="jobs-panneau-label" id="jobs-l-taille">Taille du texte</p>
        <div class="jobs-seg" role="group" aria-labelledby="jobs-l-taille">
            <template x-for="s in sizes" x-bind:key="s.value">
                <button type="button" x-on:click="setSize(s.value)" x-bind:class="{ 'jobs-on': prefs.texte === s.value }" x-bind:aria-pressed="prefs.texte === s.value ? 'true' : 'false'" x-bind:aria-label="s.name" x-text="s.label"></button>
            </template>
        </div>

        <div class="jobs-toggles">
            <template x-for="o in options" x-bind:key="o.key">
                <button type="button" role="switch" class="jobs-toggle" x-on:click="toggle(o.key)" x-bind:class="{ 'jobs-on': prefs[o.key] }" x-bind:aria-checked="prefs[o.key] ? 'true' : 'false'">
                    <span class="jobs-toggle-text">
                        <span class="jobs-toggle-label" x-text="o.label"></span>
                        <span class="jobs-toggle-hint" x-text="o.hint"></span>
                    </span>
                    <span class="jobs-switch" aria-hidden="true"><i></i></span>
                </button>
            </template>
        </div>

        <p class="jobs-panneau-note">Réglages mémorisés sur cet appareil. Raccourci : ⌥ + L (Alt + L).</p>
    </section>
</div>
