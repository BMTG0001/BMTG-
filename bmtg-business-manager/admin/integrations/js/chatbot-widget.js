(function(){
  if (typeof BMTGChatbot === 'undefined') return;
  function el(tag,cls,text){const e=document.createElement(tag); if(cls)e.className=cls; if(text!=null)e.textContent=text; return e;}
  function svg(pathD, viewBox){const s=document.createElementNS('http://www.w3.org/2000/svg','svg'); s.setAttribute('viewBox', viewBox||'0 0 24 24'); const p=document.createElementNS('http://www.w3.org/2000/svg','path'); p.setAttribute('d',pathD); s.appendChild(p); return s;}
  const ICONS={
    chat:'M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2z',
    send:'M2 21l21-9L2 3v7l15 2-15 2z',
    close:'M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z',
    user:'M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5zm0 2c-3.33 0-10 1.67-10 5v3h20v-3c0-3.33-6.67-5-10-5z',
    wa:'M20.52 3.48A11.86 11.86 0 0 0 12.07 0C5.5 0 .16 5.34.16 11.9c0 2.1.55 4.15 1.6 5.96L0 24l6.3-1.65a11.9 11.9 0 0 0 5.76 1.47h.01c6.56 0 11.9-5.34 11.9-11.9 0-3.18-1.24-6.17-3.45-8.44zM12.07 21.8h-.01a9.9 9.9 0 0 1-5.04-1.38l-.36-.21-3.74.98 1-3.64-.24-.38a9.86 9.86 0 0 1-1.51-5.27c0-5.45 4.44-9.89 9.9-9.89 2.64 0 5.12 1.03 6.99 2.9a9.82 9.82 0 0 1 2.9 6.99c0 5.45-4.44 9.9-9.89 9.9zm5.43-7.41c-.3-.15-1.77-.87-2.04-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.65.07-.3-.15-1.26-.46-2.4-1.48-.89-.79-1.49-1.77-1.66-2.07-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.62-.92-2.22-.24-.58-.49-.5-.67-.5h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.22 3.08c.15.2 2.1 3.2 5.08 4.49.71.31 1.27.49 1.7.63.71.23 1.36.2 1.87.12.57-.09 1.77-.72 2.02-1.42.25-.7.25-1.3.17-1.42-.07-.12-.27-.2-.57-.35z'
  };
  function nowTime(){try{return new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});}catch(e){return '';}}
  function renderBody(target, text){
    const src=String(text||''); const lines=src.split(/\r?\n/);
    let ul=null;
    function flush(){ul=null;}
    function inline(str){
      const re=/\*\*(.+?)\*\*/g; let i=0,m,frag=document.createDocumentFragment();
      while((m=re.exec(str))){
        if(m.index>i) frag.appendChild(document.createTextNode(str.slice(i,m.index)));
        const b=document.createElement('strong'); b.textContent=m[1]; frag.appendChild(b); i=m.index+m[0].length;
      }
      if(i<str.length) frag.appendChild(document.createTextNode(str.slice(i)));
      return frag;
    }
    for(let idx=0; idx<lines.length; idx++){
      const line=lines[idx];
      const bm=line.match(/^\s*[*\-]\s+(.*)$/);
      if(bm){
        if(!ul){ ul=document.createElement('ul'); ul.className='bmtg-ul'; target.appendChild(ul); }
        const li=document.createElement('li'); li.appendChild(inline(bm[1])); ul.appendChild(li); continue;
      }
      flush();
      if(line.trim()===''){ if(target.lastChild) target.appendChild(document.createElement('br')); continue; }
      const p=document.createElement('p'); p.className='bmtg-p'; p.appendChild(inline(line)); target.appendChild(p);
    }
  }
  function init(mount){
    if(!mount || mount.__bmtgInit) return; mount.__bmtgInit=true;
    const launcher=el('button','bmtg-chat-launcher'); launcher.type='button'; launcher.setAttribute('aria-label','Open chat');
    launcher.appendChild(svg(ICONS.chat));
    const llabel=el('span','bmtg-launcher-label','Chat with MOB'); launcher.appendChild(llabel);

    const panel=el('div','bmtg-chat-panel'); panel.setAttribute('role','dialog'); panel.setAttribute('aria-label','BM Travel Group concierge');
    const header=el('div','bmtg-chat-header');
    const avatar=el('div','bmtg-avatar'); avatar.appendChild(svg(ICONS.user));
    const htext=el('div','bmtg-htext'); htext.innerHTML='<div class="bmtg-title">MOB · Travel Concierge</div><div class="bmtg-sub">BM Travel Group</div>';
    const closeBtn=el('button','bmtg-close','×'); closeBtn.type='button'; closeBtn.setAttribute('aria-label','Close chat');
    header.appendChild(avatar); header.appendChild(htext); header.appendChild(closeBtn);

    const msgs=el('div','bmtg-chat-messages'); msgs.setAttribute('aria-live','polite');

    const quickReplies=el('div','bmtg-quick-replies');
    ['✨ Golden Triangle Tour', '🏔️ Bhutan Luxury Trek', '🐘 Sri Lanka Safari', '📅 Plan Custom Journey'].forEach(text=>{
      const chip=el('button','bmtg-quick-chip', text); chip.type='button';
      chip.addEventListener('click', ()=>{ ta.value = text.replace(/^[^\w\s]+/, '').trim(); doSend(); });
      quickReplies.appendChild(chip);
    });

    const typingContainer=el('div','bmtg-typing-container'); typingContainer.style.display='none';
    const typingIndicator=el('div','bmtg-typing-indicator');
    typingIndicator.appendChild(el('div','bmtg-typing-dot'));
    typingIndicator.appendChild(el('div','bmtg-typing-dot'));
    typingIndicator.appendChild(el('div','bmtg-typing-dot'));
    typingContainer.appendChild(typingIndicator);

    const actions=el('div','bmtg-chat-actions');
    const humanBtn=el('button',null,'Request team'); humanBtn.type='button';
    const leadBtn=el('button',null,'Share details'); leadBtn.type='button';
    actions.appendChild(humanBtn); actions.appendChild(leadBtn);
    if(BMTGChatbot.whatsappUrl){ const wa=document.createElement('a'); wa.className='bmtg-wa'; wa.href=BMTGChatbot.whatsappUrl; wa.target='_blank'; wa.rel='noopener'; wa.appendChild(svg(ICONS.wa)); wa.appendChild(document.createTextNode(' WhatsApp')); actions.appendChild(wa); }

    const leadForm=el('div','bmtg-lead-form'); leadForm.style.display='none';
    leadForm.innerHTML='<label>Name</label><input type="text" id="bmtg-lead-name" placeholder="Your name" aria-label="Name">'+
      '<label>Email</label><input type="email" id="bmtg-lead-email" placeholder="Email" aria-label="Email">'+
      '<label>Phone</label><input type="text" id="bmtg-lead-phone" placeholder="Phone" aria-label="Phone">'+
      '<label>Travel requirements</label><textarea id="bmtg-lead-req" placeholder="Destinations, dates, interests" aria-label="Travel requirements"></textarea>'+
      '<label class="bmtg-consent-row"><input type="checkbox" id="bmtg-consent-cb"> <span>I explicitly consent to share my contact details with BM Travel Group.</span></label>'+
      '<button type="button" id="bmtg-lead-submit">Submit details</button>';

    const inputWrap=el('div','bmtg-chat-input');
    const ta=document.createElement('textarea'); ta.rows=1; ta.placeholder='Type a message to MOB...'; ta.setAttribute('aria-label','Message');
    const send=el('button','bmtg-icon-btn'); send.type='button'; send.setAttribute('aria-label','Send'); send.appendChild(svg(ICONS.send));
    inputWrap.appendChild(ta); inputWrap.appendChild(send);

    panel.appendChild(header); panel.appendChild(msgs); panel.appendChild(quickReplies); panel.appendChild(typingContainer); panel.appendChild(actions); panel.appendChild(leadForm); panel.appendChild(inputWrap);
    document.body.appendChild(launcher); document.body.appendChild(panel);

    let session=null, busy=false;
    function addMsg(role,text,image,status){
      const m=el('div','bmtg-msg '+(role==='user'?'user':'assistant'));
      if(text){ const t=el('div','bmtg-body'); renderBody(t, text); m.appendChild(t); }
      if(image && image.url){ const im=document.createElement('img'); im.src=image.url; im.alt=image.alt||'Travel inspiration'; im.loading='lazy'; m.appendChild(im);
        if(image.credit){ const c=el('div','bmtg-caption', image.credit); m.appendChild(c); } }
      const meta=el('div','bmtg-meta'); meta.appendChild(document.createTextNode(nowTime()));
      if(role==='user'){ const ticks=el('span','bmtg-ticks'+(status==='pending'?' pending':''), status==='pending'?'✓':'✓✓'); ticks.title=status==='pending'?'Sent':'Delivered'; meta.appendChild(ticks); }
      m.appendChild(meta);
      msgs.appendChild(m); msgs.scrollTop=msgs.scrollHeight; return m;
    }
    function markDelivered(m){ if(!m) return; const t=m.querySelector('.bmtg-ticks'); if(t){ t.textContent='✓✓'; t.classList.remove('pending'); t.title='Delivered'; } }
    async function api(path, body){
      const r=await fetch(BMTGChatbot.restUrl+path,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':BMTGChatbot.nonce},body:JSON.stringify(Object.assign({},session||{},body||{}))});
      const d=await r.json().catch(()=>({})); if(!r.ok) throw new Error(d.error||'Request failed'); return d;
    }
    async function ensureSession(){
      if(session) return session;
      const d=await api('/session',{});
      session={session_id:d.session_id,session_token:d.session_token};
      addMsg('assistant', BMTGChatbot.greeting || 'Namaste! 👋 I am **MOB**, your luxury travel concierge at **BM Travel Group**.\n\nHow may I curate your private journey across **India, Nepal, Bhutan, or Sri Lanka** today?');
      return session;
    }
    function openPanel(){ panel.classList.add('open'); launcher.style.display='none'; ensureSession().then(()=>ta.focus()).catch(()=>addMsg('assistant','Unable to initialize chat right now. 🙏')); }
    function closePanel(){ panel.classList.remove('open'); launcher.style.display='flex'; }
    launcher.addEventListener('click', openPanel);
    closeBtn.addEventListener('click', closePanel);

    async function doSend(){
      const text=ta.value.trim(); if(!text || busy) return; ta.value=''; ta.style.height='auto';
      const userMsg=addMsg('user',text,null,'pending'); busy=true; typingContainer.style.display='block'; msgs.scrollTop=msgs.scrollHeight;
      try{
        await ensureSession();
        const d=await api('/message',{message:text});
        markDelivered(userMsg);
        typingContainer.style.display='none';
        if(d.reply || d.image) addMsg('assistant', d.reply||'', d.image||null);
        if(d.notice) addMsg('assistant', d.notice);
      }
      catch(e){
        markDelivered(userMsg);
        typingContainer.style.display='none';
        addMsg('assistant', e.message || 'Sorry, I encountered a connection issue. Please try again.');
      }
      finally{ busy=false; msgs.scrollTop=msgs.scrollHeight; }
    }
    send.addEventListener('click', doSend);
    ta.addEventListener('input', ()=>{ ta.style.height='auto'; ta.style.height=Math.min(ta.scrollHeight,100)+'px'; });
    ta.addEventListener('keydown',(e)=>{ if(e.key==='Enter' && !e.shiftKey){ e.preventDefault(); doSend(); }});
    humanBtn.addEventListener('click', async ()=>{ try{ await ensureSession(); const d=await api('/handover',{}); addMsg('assistant', d.message || 'Mr. Deepak or our concierge team has been notified and will connect with you shortly.'); }catch(e){ addMsg('assistant','Unable to request assistance right now.'); } });
    leadBtn.addEventListener('click', ()=>{ leadForm.style.display = leadForm.style.display==='none' ? 'block' : 'none'; if(leadForm.style.display==='block') leadForm.scrollIntoView({block:'nearest'}); });
    leadForm.querySelector('#bmtg-lead-submit').addEventListener('click', async ()=>{
      try {
        await ensureSession();
        const consent = !!leadForm.querySelector('#bmtg-consent-cb').checked;
        const payload = { consent: consent, name: leadForm.querySelector('#bmtg-lead-name').value || '', email: leadForm.querySelector('#bmtg-lead-email').value || '', phone: leadForm.querySelector('#bmtg-lead-phone').value || '', requirements: leadForm.querySelector('#bmtg-lead-req').value || '' };
        await api('/lead', payload);
        addMsg('assistant','Thank you! 🙏 Your travel brief has been registered with BM Travel Group.');
        leadForm.style.display='none';
      } catch(e){ addMsg('assistant', e.message || 'Unable to submit details.'); }
    });
  }
  document.addEventListener('DOMContentLoaded', function(){ document.querySelectorAll('[data-bmtg-chatbot]').forEach(init); });
})();
