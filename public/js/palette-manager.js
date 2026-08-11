(() => {
  const root = document.getElementById('marifex-palette-manager');
  if (!root || root.dataset.mounted === '1') return;
  root.dataset.mounted = '1';
  const endpoint = root.dataset.endpoint || '';
  const csrf = document.querySelector('meta[property="glpi:csrf_token"]')?.content || root.dataset.csrfToken || '';
  const list = root.querySelector('.marifex-palette-manager__list');
  const editor = root.querySelector('#marifex-palette-json');
  const preview = root.querySelector('.marifex-palette-preview');
  const message = root.querySelector('.marifex-palette-manager__message');
  let replacementSelect = root.querySelector('#marifex-palette-replacement');
  if (!replacementSelect) {
    const wrapper=document.createElement('div'),label=document.createElement('label'),hint=document.createElement('div');
    replacementSelect=document.createElement('select'); replacementSelect.id='marifex-palette-replacement'; replacementSelect.className='form-select';
    label.className='form-label'; label.htmlFor=replacementSelect.id; label.textContent='Replacement palette';
    hint.className='form-hint'; hint.textContent='Used only when deleting a custom palette. The first click reports impact; the second confirms replacement.';
    wrapper.append(label,replacementSelect,hint); root.querySelector('.marifex-palette-manager__actions').before(wrapper);
  }
  const updateButton = root.querySelector('[data-action="update"]');
  const deleteButton = root.querySelector('[data-action="delete"]');
  let catalogue = JSON.parse(root.dataset.catalogue || '{"palettes":[]}');
  let selected = null;
  let pendingAction = null;

  function resetPending() {
    pendingAction = null;
    updateButton.textContent = 'Save selected';
    deleteButton.textContent = 'Delete and replace';
  }

  const hexRgb = hex => [1,3,5].map(i => parseInt(hex.slice(i,i+2),16));
  const rgbHex = rgb => `#${rgb.map(v => Math.max(0,Math.min(255,Math.round(v))).toString(16).padStart(2,'0')).join('').toUpperCase()}`;
  const matrices = { protanopia:[[.152286,1.052583,-.204868],[.114503,.786281,.099216],[-.003882,-.048116,1.051998]], deuteranopia:[[.367322,.860646,-.227968],[.280085,.672501,.047413],[-.011820,.042940,.968881]] };
  const simulate = (hex, mode) => { const rgb=hexRgb(hex).map(v=>v/255).map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4); const linear=matrices[mode].map(row=>row.reduce((sum,v,i)=>sum+v*rgb[i],0)); return rgbHex(linear.map(v=>Math.max(0,Math.min(1,v))).map(v=>(v<=.0031308?12.92*v:1.055*v**(1/2.4)-.055)*255)); };
  const lab=hex=>{const [r,g,b]=hexRgb(hex).map(v=>v/255).map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4),xyz=[(r*.4124564+g*.3575761+b*.1804375)/.95047,r*.2126729+g*.7151522+b*.072175,(r*.0193339+g*.119192+b*.9503041)/1.08883],f=v=>v>216/24389?Math.cbrt(v):(24389/27*v+16)/116,[x,y,z]=xyz.map(f);return[116*y-16,500*(x-y),200*(y-z)];};
  const deltaE=(ah,bh)=>{const[L1,a1,b1]=lab(ah),[L2,a2,b2]=lab(bh),C1=Math.hypot(a1,b1),C2=Math.hypot(a2,b2),c=(C1+C2)/2,G=.5*(1-Math.sqrt(c**7/(c**7+25**7))),ap1=(1+G)*a1,ap2=(1+G)*a2,cp1=Math.hypot(ap1,b1),cp2=Math.hypot(ap2,b2),hp=(x,y)=>{const h=Math.atan2(y,x)*180/Math.PI;return h<0?h+360:h;},h1=hp(ap1,b1),h2=hp(ap2,b2),dL=L2-L1,dC=cp2-cp1,dh=Math.abs(h2-h1)<=180?h2-h1:h2<=h1?h2-h1+360:h2-h1-360,dH=2*Math.sqrt(cp1*cp2)*Math.sin(dh*Math.PI/360),Lm=(L1+L2)/2,Cm=(cp1+cp2)/2,hm=Math.abs(h1-h2)<=180?(h1+h2)/2:(h1+h2<360?(h1+h2+360)/2:(h1+h2-360)/2),T=1-.17*Math.cos((hm-30)*Math.PI/180)+.24*Math.cos(2*hm*Math.PI/180)+.32*Math.cos((3*hm+6)*Math.PI/180)-.2*Math.cos((4*hm-63)*Math.PI/180),Sl=1+.015*(Lm-50)**2/Math.sqrt(20+(Lm-50)**2),Sc=1+.045*Cm,Sh=1+.015*Cm*T,Rt=-2*Math.sqrt(Cm**7/(Cm**7+25**7))*Math.sin(60*Math.exp(-1*((hm-275)/25)**2)*Math.PI/180);return Math.sqrt((dL/Sl)**2+(dC/Sc)**2+(dH/Sh)**2+Rt*(dC/Sc)*(dH/Sh));};
  const definition = palette => ({ schemaVersion:1, name:palette.name, type:palette.type, ...(palette.colors ? {colors:palette.colors} : {baseColor:palette.baseColor,slotCount:palette.slotCount,lightnessSpan:palette.lightnessSpan}), areaOpacity:palette.areaOpacity, isRecursive:Boolean(palette.isRecursive) });
  const colors = value => value.colors || (value.baseColor ? Array.from({length:value.slotCount || 6},()=>value.baseColor) : []);

  function drawPreview() {
    preview.replaceChildren();
    let value; try { value = JSON.parse(editor.value); } catch { return; }
    ['normal','protanopia','deuteranopia'].forEach(mode => {
      const row=document.createElement('div'), label=document.createElement('strong'); label.textContent=mode; row.append(label);
      colors(value).forEach(color => { const swatch=document.createElement('span'); swatch.style.backgroundColor=mode==='normal'?color:simulate(color,mode); swatch.title=color; row.append(swatch); });
      preview.append(row);
    });
    const warnings=[]; ['protanopia','deuteranopia'].forEach(mode=>{const set=colors(value).map(color=>simulate(color,mode));for(let i=0;i<set.length;i++)for(let j=i+1;j<set.length;j++)if(deltaE(set[i],set[j])<10)warnings.push(`${mode}: colours ${i+1} and ${j+1} are below ΔE00 10.`);});
    if(warnings.length){const warning=document.createElement('p');warning.className='text-warning';warning.textContent=warnings.slice(0,4).join(' ');preview.append(warning);}
  }
  function render() {
    list.replaceChildren();
    catalogue.palettes.forEach(palette => {
      const button=document.createElement('button'); button.type='button'; button.className='marifex-palette-row'; button.dataset.id=palette.id;
      const title=document.createElement('strong'); title.textContent=palette.name; const meta=document.createElement('small'); meta.textContent=`${palette.type} · r${palette.revision}${palette.inherited?' · inherited':''}${catalogue.default===palette.id?' · default':''}`;
      const swatches=document.createElement('span'); swatches.className='marifex-palette-row__swatches'; colors(palette).slice(0,12).forEach(color=>{const item=document.createElement('i');item.style.backgroundColor=color;swatches.append(item);});
      button.append(title,meta,swatches); button.addEventListener('click',()=>{resetPending();selected=palette;editor.value=JSON.stringify(definition(palette),null,2);drawPreview();render();});
      if (selected?.id===palette.id) button.classList.add('is-selected'); list.append(button);
    });
    const previousReplacement = replacementSelect.value;
    replacementSelect.replaceChildren();
    catalogue.palettes.filter(palette => palette.id !== selected?.id).forEach(palette => {
      const option=document.createElement('option'); option.value=palette.id; option.textContent=`${palette.name} · r${palette.revision}`; replacementSelect.append(option);
    });
    const preferred=[previousReplacement,catalogue.default,'chart_cream_gold'].find(key=>key&&[...replacementSelect.options].some(option=>option.value===key));
    if(preferred) replacementSelect.value=preferred;
  }
  async function request(method, body) {
    message.textContent='Saving…';
    const response=await fetch(endpoint,{method,credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-Glpi-Csrf-Token':csrf,'X-Requested-With':'XMLHttpRequest'},body:JSON.stringify(body)});
    const result=await response.json().catch(()=>({})); if(!response.ok) throw new Error(result.detail||result.message||'Palette operation failed.'); return result;
  }
  async function operation(action) {
    try {
      if(action==='reverse'){resetPending();const value=JSON.parse(editor.value);if(Array.isArray(value.colors))value.colors.reverse();editor.value=JSON.stringify(value,null,2);drawPreview();return;}
      if(action==='duplicate'){resetPending();const value=JSON.parse(editor.value);value.name=`${value.name} copy`.slice(0,50);editor.value=JSON.stringify(value,null,2);selected=null;drawPreview();render();return;}
      if(action==='export'){const blob=new Blob([editor.value],{type:'application/json'}),link=document.createElement('a');link.href=URL.createObjectURL(blob);link.download=`${selected?.name||'chart-palette'}.json`;link.click();URL.revokeObjectURL(link.href);return;}
      if(action==='create'){resetPending();catalogue=await request('POST',{action:'import',json:editor.value});}
      else if(action==='update'){if(!selected||selected.builtIn||selected.readOnly)throw new Error('Select a locally owned custom palette.');const id=Number(selected.id.slice(7)),confirmed=pendingAction?.type==='update'&&pendingAction.id===id;let result=await request('PUT',{id,palette:JSON.parse(editor.value),confirmed});if(result.confirmationRequired){pendingAction={type:'update',id};updateButton.textContent='Confirm revision';message.textContent=`Revision affects ${result.impact.widgets} widgets on ${result.impact.dashboards} dashboards and ${result.impact.childEntities} child entities. Click Confirm revision to continue.`;return;}catalogue=result;resetPending();}
      else if(action==='default'){if(!selected)throw new Error('Select a palette.');catalogue=await request('POST',{action:'default',key:selected.id});}
      else if(action==='delete'){if(!selected||selected.builtIn||selected.readOnly)throw new Error('Select a locally owned custom palette.');const id=Number(selected.id.slice(7)),replacement=replacementSelect.value;if(!replacement)throw new Error('Select a replacement palette.');const confirmed=pendingAction?.type==='delete'&&pendingAction.id===id&&pendingAction.replacement===replacement;let result=await request('DELETE',{id,replacement,confirmed});if(result.confirmationRequired){pendingAction={type:'delete',id,replacement};deleteButton.textContent='Confirm delete & replace';message.textContent=`Replacement affects ${result.impact.widgets} widgets across ${result.impact.dashboards} dashboards. Click Confirm delete & replace to continue.`;return;}catalogue=result;selected=null;resetPending();}
      message.textContent='Palette governance updated.'; render();
    } catch(error) { message.textContent=error instanceof Error?error.message:'Palette operation failed.'; }
  }
  root.querySelectorAll('[data-action]').forEach(button=>button.addEventListener('click',()=>operation(button.dataset.action)));
  editor.addEventListener('input',()=>{resetPending();drawPreview();}); replacementSelect.addEventListener('change',resetPending); render();
})();
