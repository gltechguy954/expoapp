(function(){
    function renderQr(element, url) {
        if (!element || !url) return;
        while (element.firstChild) {
            element.removeChild(element.firstChild);
        }
        new QRCode(element, {
            text: url,
            width: element.classList.contains('uc-label-qr') ? 220 : 160,
            height: element.classList.contains('uc-label-qr') ? 220 : 160,
            correctLevel: QRCode.CorrectLevel.M
        });
    }

    function boot() {
        var qrBlocks = document.querySelectorAll('.uc-nursery-qr[data-url], .uc-label-qr[data-url]');
        qrBlocks.forEach(function(block){
            renderQr(block, block.getAttribute('data-url'));
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
