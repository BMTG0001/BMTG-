(function(){
  if(typeof BMTGCRM==='undefined') return;
  const root=document.getElementById('bmtg-crm-root'); if(!root) return;
  const state={view:BMTGCRM.page||'bmtg-crm',leads:[],customers:[],reminders:[],meta:{},selectedLead:null,activities:[],msg:''};
  function api(path,opts={}){
    const url=BMTGCRM.restUrl+path+(path.includes('?')?'&':'?')+'_='+Date.now();
    return fetch(url,Object.assign({headers:{'X-WP-Nonce':BMTGCRM.nonce,'Content-Type':'application/json'},credentials:'same-origin'},opts))
      .then(async r=>{const d=await r.json().catch(()=>({})); if(!r.ok) throw new Error(d.error||('HTTP '+r.status)); return d;});
  }
  function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}
  async function load(){ try{
    const [meta,leads,customers,reminders]=await Promise.all([
      api('/meta').catch(()=>({})),
      BMTGCRM.can ? api('/leads?per_page=30') : Promise.resolve({items:[]}),
      api('/customers?per_page=30').catch(()=>({items:[]})),
      api('/reminders?per_page=30').catch(()=>({items:[]}))
    ]);
    state.meta=meta||{}; state.leads=(leads.items||[]); state.customers=(customers.items||[]); state.reminders=(reminders.items||[]);
    render();
  }catch(e){root.innerHTML='<div class="bmtg-crm-notice error">'+esc(e.message)+'</div>';} }
  async function openLead(id){ try{ const d=await api('/leads/'+id); state.selectedLead=d; const a=await api('/leads/'+id+'/activities?per_page=30'); state.activities=a.items||[]; render(); }catch(e){alert(e.message)} }
  function render(){
    const tabs=`<div class="bmtg-crm-row"><button class="button ${state.view==='bmtg-crm'?'button-primary':''}" data-nav="bmtg-crm">Leads</button><button class="button ${state.view==='bmtg-crm-customers'?'button-primary':''}" data-nav="bmtg-crm-customers">Customers</button><button class="button ${state.view==='bmtg-crm-reminders'?'button-primary':''}" data-nav="bmtg-crm-reminders">Reminders</button><button class="button" data-act="refresh">Refresh</button></div>`;
    const msg=state.msg?`<div class="bmtg-crm-notice">${esc(state.msg)}</div>`:'';
    let body='';
    if(state.view==='bmtg-crm-customers') body=renderCustomers();
    else if(state.view==='bmtg-crm-reminders') body=renderReminders();
    else body=renderLeads();
    root.innerHTML=tabs+msg+body; bind();
  }
  function renderLeads(){
    const rows=state.leads.map(l=>`<tr><td>#${l.id}<div class="bmtg-crm-muted">${esc(l.source||'')}/${esc(l.channel||'')}</div></td><td>${esc(l.title||'')}</td><td><span class="bmtg-crm-badge">${esc(l.status||'')}</span></td><td>${esc(l.assigned_to||'-')}</td><td class="bmtg-crm-actions"><button class="button button-small" data-lead="${l.id}">Open</button></td></tr>`).join('')||'<tr><td colspan="5" class="bmtg-crm-muted">No leads yet. Chatbot lead capture will appear here after consented submission.</td></tr>';
    const detail=state.selectedLead?renderLeadDetail(state.selectedLead):'<div class="bmtg-crm-muted">Select a lead to view timeline and actions.</div>';
    return `<div class="bmtg-crm-grid"><div class="bmtg-crm-card"><h2>Leads</h2><table class="bmtg-crm-table"><thead><tr><th>ID</th><th>Title</th><th>Status</th><th>Assigned</th><th></th></tr></thead><tbody>${rows}</tbody></table></div><div class="bmtg-crm-card"><h2>Lead detail</h2>${detail}</div></div>`;
  }
  function renderLeadDetail(l){
    const statuses=(state.meta.lead_statuses||['new','contacted','qualified','proposal','negotiating','won','lost','on_hold']).map(s=>`<option value="${s}" ${s===l.status?'selected':''}>${s}</option>`).join('');
    const acts=(state.activities||[]).map(a=>`<li><strong>${esc(a.type||'')}</strong> <span class="bmtg-crm-muted">${esc(a.created_at||'')}</span><div>${esc(a.body||'')}</div></li>`).join('')||'<li class="bmtg-crm-muted">No activities.</li>';
    return `<div><div><strong>#${l.id} ${esc(l.title||'')}</strong></div><div class="bmtg-crm-muted">Customer #${esc(l.customer_id)} · ${esc(l.source||'')} · conv ${esc(l.inbox_conversation_id||'-')}</div>
      <div class="bmtg-crm-form"><label>Status</label><select id="bmtg-lead-status">${statuses}</select><label>Reason (optional)</label><input id="bmtg-lead-reason" type="text"/><button class="button" data-act="status" data-id="${l.id}">Update status</button>
      <label>Assign to user ID</label><input id="bmtg-lead-assign" type="number" value="${esc(l.assigned_to||'')}"/><button class="button" data-act="assign" data-id="${l.id}">Assign</button>
      <label>Add note</label><textarea id="bmtg-lead-note" rows="3"></textarea><button class="button" data-act="note" data-id="${l.id}">Add note</button>
      <label>Add reminder (YYYY-MM-DD HH:MM:SS UTC)</label><input id="bmtg-rem-due" type="text" placeholder="2026-12-01 09:00:00"/><input id="bmtg-rem-note" type="text" placeholder="Follow up"/><button class="button" data-act="reminder" data-id="${l.id}">Create reminder</button></div>
      <h3 style="margin-top:14px">Timeline</h3><ul class="bmtg-crm-list">${acts}</ul></div>`;
  }
  function renderCustomers(){
    const rows=state.customers.map(c=>`<tr><td>#${c.id}</td><td>${esc((c.first_name||'')+' '+(c.last_name||''))}</td><td>${esc(c.email||'')}</td><td>${esc(c.phone||'')}</td><td>${esc(c.source||'')}</td></tr>`).join('')||'<tr><td colspan="5" class="bmtg-crm-muted">No customers yet.</td></tr>';
    return `<div class="bmtg-crm-card"><h2>Customers</h2><table class="bmtg-crm-table"><thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Source</th></tr></thead><tbody>${rows}</tbody></table></div>`;
  }
  function renderReminders(){
    const rows=state.reminders.map(r=>`<tr><td>#${r.id}</td><td>Lead #${esc(r.lead_id)}</td><td>${esc(r.due_at)}</td><td><span class="bmtg-crm-badge">${esc(r.status)}</span></td><td>${esc(r.note||'')}</td><td>${r.status==='pending'?`<button class="button button-small" data-complete-rem="${r.id}">Complete</button>`:''}</td></tr>`).join('')||'<tr><td colspan="6" class="bmtg-crm-muted">No reminders.</td></tr>';
    return `<div class="bmtg-crm-card"><h2>Reminders</h2><table class="bmtg-crm-table"><thead><tr><th>ID</th><th>Lead</th><th>Due (UTC)</th><th>Status</th><th>Note</th><th></th></tr></thead><tbody>${rows}</tbody></table></div>`;
  }
  function bind(){
    root.querySelectorAll('[data-nav]').forEach(b=>b.onclick=()=>{state.view=b.dataset.nav; render();});
    root.querySelectorAll('[data-act="refresh"]').forEach(b=>b.onclick=()=>load());
    root.querySelectorAll('[data-lead]').forEach(b=>b.onclick=()=>openLead(b.dataset.lead));
    root.querySelectorAll('[data-complete-rem]').forEach(b=>b.onclick=async()=>{try{await api('/reminders/'+b.dataset.completeRem+'/complete',{method:'POST'}); state.msg='Reminder completed.'; load();}catch(e){alert(e.message)}});
    const statusBtn=root.querySelector('[data-act="status"]');
    if(statusBtn) statusBtn.onclick=async()=>{try{await api('/leads/'+statusBtn.dataset.id+'/status',{method:'POST',body:JSON.stringify({status:document.getElementById('bmtg-lead-status').value,reason:document.getElementById('bmtg-lead-reason').value})}); state.msg='Status updated.'; await openLead(statusBtn.dataset.id); load();}catch(e){alert(e.message)}};
    const assignBtn=root.querySelector('[data-act="assign"]');
    if(assignBtn) assignBtn.onclick=async()=>{try{const v=document.getElementById('bmtg-lead-assign').value; await api('/leads/'+assignBtn.dataset.id+'/assign',{method:'POST',body:JSON.stringify({assigned_to:v?parseInt(v,10):null})}); state.msg='Lead assigned.'; await openLead(assignBtn.dataset.id); load();}catch(e){alert(e.message)}};
    const noteBtn=root.querySelector('[data-act="note"]');
    if(noteBtn) noteBtn.onclick=async()=>{try{await api('/leads/'+noteBtn.dataset.id+'/activities',{method:'POST',body:JSON.stringify({type:'note',body:document.getElementById('bmtg-lead-note').value})}); document.getElementById('bmtg-lead-note').value=''; await openLead(noteBtn.dataset.id);}catch(e){alert(e.message)}};
    const remBtn=root.querySelector('[data-act="reminder"]');
    if(remBtn) remBtn.onclick=async()=>{try{await api('/reminders',{method:'POST',body:JSON.stringify({lead_id:parseInt(remBtn.dataset.id,10),due_at:document.getElementById('bmtg-rem-due').value,note:document.getElementById('bmtg-rem-note').value,assigned_to:state.selectedLead?state.selectedLead.assigned_to:null})}); state.msg='Reminder created.'; load();}catch(e){alert(e.message)}};
  }
  load();
})();
