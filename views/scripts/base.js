window.addEventListener("load", function() 
{ 
//let btn = document.getElementById('btn_login');
//btn.click();			
	
  //let fData = new FormData();
  //fData.append('route', 'web');
  //fData.append('type', 'index');  
  
  fetch('/web/', 
    {
      method: 'POST'
      //headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      //body: fData
    })  
    .then(res => res.text())
    .then(data => document.querySelector('html').innerHTML = data); 
});  