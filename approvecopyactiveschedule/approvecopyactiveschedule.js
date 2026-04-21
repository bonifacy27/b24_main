////////////////////////////////////////////////////////////////////////////////////////
// approvecopyactiveschedule
////////////////////////////////////////////////////////////////////////////////////////

approvecopyactiveschedule = function()
{
	var ob = new ParallelActivity();
	ob.Type = 'approvecopyactiveschedule';
	ob.__parallelActivityInitType = 'SequenceActivity';

	ob.DrawParallelActivity = ob.Draw;

	ob.Draw = function (d)
	{
		var act = _crt(1, 4);
		act.style.fontSize = '11px';

		act.rows[0].cells[1].style.background = 'url('+ob.Icon+') 2px 2px no-repeat';
		act.rows[0].cells[1].style.height = '24px';
		act.rows[0].cells[1].style.width = '24px';

		act.rows[0].cells[2].align = 'left';
		act.rows[0].cells[2].innerHTML = HTMLEncode(ob['Properties']['Title']);

		act.rows[0].cells[0].width = '33';
		act.rows[0].cells[0].align = 'left';
		act.rows[0].cells[0].innerHTML = '&nbsp;<span style="color: #007700">'+BPMESS['APPR_YES']+'</span>';
		act.rows[0].cells[3].align = 'right';
		act.rows[0].cells[3].innerHTML = '<span style="color: #770000">'+BPMESS['APPR_NO']+'</span>&nbsp;';

		ob.activityContent = act;
		ob.activityHeight = '30px';
		ob.activityWidth = '200px';
		ob.DrawParallelActivity(d);
	}

	return ob;
}



/**
 * Hook: add custom CSS class for "refine" button in task popup
 */
;(function(){
	if (typeof BX === 'undefined') { return; }
	var apply = function(){
		var btns = document.querySelectorAll('button[name="refine"], input[name="refine"]');
		if (!btns || !btns.length) return;
		for (var i=0;i<btns.length;i++){
			var b = btns[i];
			if (b.classList){
				b.classList.add('ui-btn-rework');
				b.classList.remove('ui-btn-danger');
			}else{
				// Fallback for very old browsers
				b.className += ' ui-btn-rework';
			}
		}
	};
	BX.ready(apply);
	BX.addCustomEvent('BPTaskPopupOpen', apply);
})();

/* ensure refine flag submitted even in ajax */
(function(){
  if (typeof document === 'undefined') return;
  document.addEventListener('click', function(ev){
    var el = ev.target;
    if (!el) return;
    // if click inside element that is refine submit
    var btn = el.closest ? el.closest('button[name="refine"], input[name="refine"]') : null;
    if (!btn) return;
    var form = btn.form || (btn.closest ? btn.closest('form') : null);
    if (!form) return;
    var hidden = form.querySelector('input[name="REFINE"]');
    if (!hidden){
      hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = 'REFINE';
      form.appendChild(hidden);
    }
    hidden.value = 'Y';
  }, true);
})();
