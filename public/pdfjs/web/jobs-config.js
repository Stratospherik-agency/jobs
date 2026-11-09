// Réglages du tableau de bord pour PDF.js : interface en français, scripts embarqués dans les PDF désactivés.
document.addEventListener('webviewerloaded', function () {
    PDFViewerApplicationOptions.set('localeProperties', { lang: 'fr' });
    PDFViewerApplicationOptions.set('enableScripting', false);
});
