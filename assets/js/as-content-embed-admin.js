document.addEventListener('DOMContentLoaded', function () {
    // Finde alle Kopieren-Buttons auf der Seite
    document.querySelectorAll('.as-embed-button').forEach(button => {
        
        button.addEventListener('click', function() {
            // Finde das zugehörige Input-Feld über das data-target Attribut
            const targetSelector = this.getAttribute('data-target');
            const input = document.querySelector(targetSelector);
            
            // Finde die Icons innerhalb des geklickten Buttons
            const defaultIcon = this.querySelector('.icon-default');
            const successIcon = this.querySelector('.icon-success');

            if (!input || !defaultIcon || !successIcon) {
                console.error('Benötigte Elemente für den Kopiervorgang nicht gefunden.');
                return;
            }

            // Kopiere den Text in die Zwischenablage
            navigator.clipboard.writeText(input.value).then(() => {
                // Bei Erfolg: Icon wechseln
                defaultIcon.style.display = 'none';
                successIcon.style.display = 'inline-block';

                // Nach 2 Sekunden den Zustand zurücksetzen
                setTimeout(() => {
                    defaultIcon.style.display = 'inline-block';
                    successIcon.style.display = 'none';
                }, 2000);
                
            }).catch(err => {
                // Bei einem Fehler eine Nachricht in der Konsole ausgeben
                console.error('Konnte nicht in die Zwischenablage kopieren:', err);
            });
        });
    });
});