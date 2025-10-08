(function(){
  document.addEventListener('DOMContentLoaded', function(){
    var nodes = document.querySelectorAll('.uc-qr-img[data-url]');
    nodes.forEach(function(el){
      var url  = el.getAttribute('data-url');
      var size = parseInt(el.getAttribute('data-size') || '220', 10);
      try { new QRCode(el, { text:url, width:size, height:size, correctLevel: QRCode.CorrectLevel.M }); }
      catch(e){ el.innerHTML = '<em>QR failed</em>'; console.error(e); }
    });

    var bulkForms = document.querySelectorAll('form.uc-list');
    bulkForms.forEach(function(f){
      f.addEventListener('submit', function(e){
        var a1 = f.querySelector('select[name="action"]'); var a2 = f.querySelector('select[name="action2"]');
        var act1 = a1 ? a1.value : ''; var act2 = a2 ? a2.value : '';
        if (act1==='delete' || act2==='delete') {
          if (!confirm(UCExpoQR.confirmDelete)) e.preventDefault();
        }
      });
    });
  });
})();