(function(){
  if (typeof BMTGChatbot === 'undefined') return;
  function el(tag,cls,text){const e=document.createElement(tag); if(cls)e.className=cls; if(text!=null)e.textContent=text; return e;}
  function init(mount){
    if(!mount || mount.__bmtgInit) return; mount.__bmtgInit=true;
    const launcher=el('button','bmtg-chat-launcher','Chat with BMTG'); launcher.type='button'; launcher.setAttribute('aria-label','Open chat');
    const panel=el('div','bmtg-chat-panel'); panel.setAttribute('role','dialog'); panel.setAttribute('aria-label','BM Travel Group chat');
    const header=el('div','bmtg-chat-header','BM Travel Group');
    const msgs=el('div','bmtg-chat-messages'); msgs.setAttribute('aria-live','polite');
    const actions=el('div','bmtg-chat-actions');
    const humanBtn=el('button',null,'Request human assistance'); humanBtn.type='button';
    const leadBtn=el('button',null,'Share travel details'); leadBtn.type='button';
    actions.appendChild(humanBtn); actions.appendChild(leadBtn);
    const leadForm=el('div','bmtg-lead-form'); leadForm.style.display='none';
    leadForm.innerHTML='<div class="bmtg-consent"><label><input type="checkbox" id="bmtg-consent-cb"> I explicitly consent to share my contact and travel requirements so BM Travel Group can assist me.</label></div>'+
      '<input type="text" id="bmtg-lead-name" placeholder="Name" aria-label="Name">'+
      '<input type="email" id="bmtg-lead-email" placeholder="Email" aria-label="Email">'+
      '<input type="text" id="bmtg-lead-phone" placeholder="Phone" aria-label="Phone">'+
      '<textarea id="bmtg-lead-req" placeholder="Travel requirements (destinations, dates, interests)" aria-label="Travel requirements"></textarea>'+
      '<button type="button" id="bmtg-lead-submit">Submit details</button>';
    const consent=el('div','bmtg-consent', BMTGChatbot.requireConsent ? 'Contact details are collected only if you request assistance and explicitly consent.' : '');
    const inputWrap=el('div','bmtg-chat-input');
    const ta=document.createElement('textarea'); ta.placeholder='Write your message...'; ta.setAttribute('aria-label','Message');
    const send=el('button',null,'Send'); send.type='button';
    inputWrap.appendChild(ta); inputWrap.appendChild(send);
    panel.appendChild(header); panel.appendChild(msgs); panel.appendChild(actions); panel.appendChild(leadForm); panel.appendChild(consent); panel.appendChild(inputWrap);
    document.body.appendChild(launcher); document.body.appendChild(panel);

    let session=null, busy=false;
    function addMsg(role,text){ const m=el('div','bmtg-msg '+(role==='user'?'user':'assistant')); m.textContent=text; msgs.appendChild(m); msgs.scrollTop=msgs.scrollHeight; }
    async function api(path, body){
      const r=await fetch(BMTGChatbot.restUrl+path,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':BMTGChatbot.nonce},body:JSON.stringify(Object.assign({},session||{},body||{}))});
      const d=await r.json().catch(()=>({})); if(!r.ok) throw new Error(d.error||'Request failed'); return d;
    }
    async function ensureSession(){
      if(session) return session;
      const d=await api('/session',{});
      session={session_id:d.session_id,session_token:d.session_token};
      addMsg('assistant', BMTGChatbot.greeting || 'Welcome to BM Travel Group.');
      if(BMTGChatbot.operatingHours) addMsg('assistant', 'Hours: '+BMTGChatbot.operatingHours);
      return session;
    }
    launcher.addEventListener('click', async ()=>{ panel.classList.toggle('open'); if(panel.classList.contains('open')){ try{await ensureSession(); ta.focus();}catch(e){addMsg('assistant','Unable to start chat right now.');} } });
    async function doSend(){
      const text=ta.value.trim(); if(!text || busy) return; ta.value=''; addMsg('user',text); busy=true;
      try{ await ensureSession(); const d=await api('/message',{message:text}); if(d.reply) addMsg('assistant', d.reply); if(d.notice) addMsg('assistant', d.notice); }
      catch(e){ addMsg('assistant', e.message || 'Unable to respond right now.'); }
      finally{ busy=false; }
    }
    send.addEventListener('click', doSend);
    ta.addEventListener('keydown',(e)=>{ if(e.key==='Enter' && !e.shiftKey){ e.preventDefault(); doSend(); }});
    humanBtn.addEventListener('click', async ()=>{ try{ await ensureSession(); const d=await api('/handover',{}); addMsg('assistant', d.message || BMTGChatbot.handoverMessage || 'A team member will assist you shortly.'); }catch(e){ addMsg('assistant','Unable to request handover right now.'); } });
    leadBtn.addEventListener('click', ()=>{ leadForm.style.display = leadForm.style.display==='none' ? 'block' : 'none'; });
    leadForm.querySelector('#bmtg-lead-submit').addEventListener('click', async ()=>{
      try {
        await ensureSession();
        const consent = !!leadForm.querySelector('#bmtg-consent-cb').checked;
        const payload = {
          consent: consent,
          name: leadForm.querySelector('#bmtg-lead-name').value || '',
          email: leadForm.querySelector('#bmtg-lead-email').value || '',
          phone: leadForm.querySelector('#bmtg-lead-phone').value || '',
          requirements: leadForm.querySelector('#bmtg-lead-req').value || ''
        };
        await api('/lead', payload);
        addMsg('assistant','Thank you. Your details have been shared with our team to assist you.');
        leadForm.style.display='none';
      } catch(e){ addMsg('assistant', e.message || 'Unable to submit details.'); }
    });
  }
  document.addEventListener('DOMContentLoaded', function(){ document.querySelectorAll('[data-bmtg-chatbot]').forEach(init); });
})();
