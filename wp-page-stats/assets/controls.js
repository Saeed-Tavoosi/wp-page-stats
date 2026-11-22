(function () {
  'use strict';
  const panel = document.querySelector('.wps');
  if (!panel) return;
  let current = null;
  let serial = 0;
  function close(restoreFocus) {
    if (!current) return;
    const {button, list} = current;
    current = null;
    list.remove();
    button.setAttribute('aria-expanded', 'false');
    button.removeAttribute('aria-controls');
    if (restoreFocus) button.focus();
  }
  function open(select, button) {
    close(false);
    const list = document.createElement('div');
    list.className = 'wps-options';
    // The list lives outside the panel to avoid clipping, so copy the trigger's font.
    list.style.fontFamily = getComputedStyle(button).fontFamily;
    list.dir = 'rtl';
    list.setAttribute('role','listbox');
    list.setAttribute('aria-label', button.getAttribute('aria-label'));
    list.tabIndex = -1;
    list.id = 'wps-options-' + (++serial);
    const options = Array.from(select.options);
    let index = select.selectedIndex;
    function highlight(i) {
      index = i;
      Array.from(list.children).forEach((el,k) => el.classList.toggle('is-current', k === i));
      list.setAttribute('aria-activedescendant',list.children[i].id);
      list.children[i].scrollIntoView({block:'nearest'});
    }
    function choose(i) {
      if (options[i].disabled) return;
      select.selectedIndex = i;
      select.dispatchEvent(new Event('change',{bubbles:true}));
      close(true);
    }
    options.forEach((option,i) => {
      const item = document.createElement('div');
      item.id = list.id + '-' + i;
      item.setAttribute('role','option');
      item.setAttribute('aria-selected',String(i===select.selectedIndex));
      item.setAttribute('aria-disabled',String(option.disabled));
      item.textContent = option.text;
      item.addEventListener('click', () => choose(i));
      list.appendChild(item);
    });
    document.body.appendChild(list);
    const rect = button.getBoundingClientRect();
    list.style.width = rect.width+'px';
    list.style.left = rect.left+'px';
    if (innerHeight-rect.bottom < 180 && rect.top > 180) list.style.bottom=(innerHeight-rect.top+4)+'px';
    else list.style.top=(rect.bottom+4)+'px';
    current = {button,list};
    button.setAttribute('aria-controls', list.id);
    button.setAttribute('aria-expanded','true');
    list.focus({preventScroll:true});
    highlight(Math.max(0,index));
    list.addEventListener('keydown', e => {
      if (e.key==='Escape' || e.key==='Tab') { close(true); if(e.key==='Escape') e.preventDefault(); return; }
      if (e.key==='Enter' || e.key===' ') { e.preventDefault(); choose(index); return; }
      const enabled = options.map((o,i)=>o.disabled ? -1 : i).filter(i=>i>=0);
      let next;
      if (e.key==='Home') next = enabled[0];
      else if (e.key==='End') next = enabled[enabled.length-1];
      else if (e.key==='ArrowDown' || e.key==='ArrowUp') next = enabled[(enabled.indexOf(index)+(e.key==='ArrowDown'?1:-1)+enabled.length)%enabled.length];
      else if (e.key.length===1) next = enabled.find(i=>options[i].text.toLowerCase().startsWith(e.key.toLowerCase()));
      if(next!==undefined) { e.preventDefault(); highlight(next); }
    });
  }
  const ready = new WeakSet();
  function enhance(select) {
    if (ready.has(select)) return;
    ready.add(select);
    const old=select.nextElementSibling;
    if(old && old.classList.contains('wps-select-button')) old.remove();
    const label=select.closest('label');
    const title=label ? Array.from(label.childNodes).filter(n=>n.nodeType===3).map(n=>n.textContent.trim()).join(' ') : '';
    const button=document.createElement('button');
    button.type='button'; button.className='wps-select-button';
    button.setAttribute('aria-haspopup','listbox'); button.setAttribute('aria-expanded','false');
    function refresh() {
      button.textContent=select.options[select.selectedIndex]?.text || '';
      button.setAttribute('aria-label', title+'، '+button.textContent);
    }
    select.hidden=true;
    select.after(button); refresh();
    select.addEventListener('change', refresh);
    button.addEventListener('click',()=>current?.button===button ? close(true) : open(select,button));
    button.addEventListener('keydown',e=>{if(e.key==='ArrowDown'){e.preventDefault(); open(select,button);}});
  }
  panel.querySelectorAll('select').forEach(enhance);
  new MutationObserver(records=>records.forEach(r=>r.addedNodes.forEach(n=>{if(n.querySelectorAll)n.querySelectorAll('select').forEach(enhance);}))).observe(panel,{childList:true,subtree:true});
  document.addEventListener('pointerdown',e=>{if(current && !current.list.contains(e.target) && !current.button.contains(e.target))close(false);});
  window.addEventListener('resize',()=>close(false));
  document.addEventListener('scroll',e=>{if(current && !current.list.contains(e.target))close(false);},true);
  const keep=panel.querySelector('[name=keep_forever]'), days=panel.querySelector('[name=retention]');
  if(keep && days){const update=()=>{days.readOnly=keep.checked;days.closest('label').classList.toggle('wps-inactive',keep.checked);};keep.addEventListener('change',update);update();}
}());
