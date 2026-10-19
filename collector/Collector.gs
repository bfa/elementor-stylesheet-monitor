/* Local source only. No secrets or live destinations belong in this file. */
function esmConfig_() {
  const p = PropertiesService.getScriptProperties();
  const config = JSON.parse(p.getProperty('ESM_CONFIG') || '{}');
  if (!Array.isArray(config.sites) || !config.sites.length || config.sites.length > 10) throw new Error('Configure 1–10 sites');
  if (config.reportHour !== undefined && (!Number.isInteger(config.reportHour) || config.reportHour<0 || config.reportHour>23)) throw new Error('Invalid report hour');
  Utilities.formatDate(new Date(),config.timezone || 'UTC','yyyy-MM-dd');
  const ids = new Set();
  for (const s of config.sites) {
    if (!/^[a-z0-9-]{1,40}$/.test(s.id) || ids.has(s.id)) throw new Error('Invalid or duplicate site ID');
    ids.add(s.id);

  }
  return config;
}
function esmPrepareSite_(input,config,needKey) {
  const s=Object.assign({},input);
    if (!/^https:\/\/[^/?#\s@]+(?:\/[^?#\s]*)?$/.test(s.site) || !s.folderId || !/^[^\s@,]+@[^\s@,]+\.[^\s@,]+$/.test(s.recipient)) throw new Error('Invalid site configuration');
    s.site = s.site.replace(/\/$/,'');
    s.label = String(s.label || s.id).replace(/[\r\n]/g,' ').slice(0,120);
    s.zone = s.zone || config.timezone || 'UTC';
    Utilities.formatDate(new Date(),s.zone,'yyyy-MM-dd');
    s.cssBaseUrl = s.cssBaseUrl || s.site + '/wp-content/uploads/elementor/css/';
    if (!/^https:\/\/[^/?#\s@]+\/[^?#\s]*\/$/.test(s.cssBaseUrl)) throw new Error('Invalid CSS base URL');
    s.secret = PropertiesService.getScriptProperties().getProperty('ESM_KEY_' + s.id);
    if (needKey && !/^[a-f0-9]{64}$/.test(s.secret || '')) throw new Error('Missing site signing key');
  return s;
}
function esmHex_(bytes) { return bytes.map(b=>('0'+((b+256)%256).toString(16)).slice(-2)).join(''); }
function esmHash_(text) { return esmHex_(Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256,text,Utilities.Charset.UTF_8)); }
function esmRequest_(site, method, path, data) {
  const body = data === undefined ? '' : JSON.stringify(data);
  const stamp = String(Math.floor(Date.now()/1000));
  const nonce = Utilities.getUuid().replace(/-/g,'');
  const signature = esmHex_(Utilities.computeHmacSha256Signature(method+'\n'+path+'\n'+stamp+'\n'+nonce+'\n'+esmHash_(body),site.secret,Utilities.Charset.UTF_8));
  const options = {method:method.toLowerCase(),followRedirects:false,muteHttpExceptions:true,
    headers:{'X-ESM-Time':stamp,'X-ESM-Nonce':nonce,'X-ESM-Signature':signature,'Cache-Control':'no-cache'}};
  if (method === 'POST') { options.contentType='application/json'; options.payload=body; }
  const response=UrlFetchApp.fetch(site.site+'/wp-json'+path,options);
  if (response.getResponseCode()!==200) throw new Error('Signed request failed');
  const text=response.getContentText();
  if (text.length>500000) throw new Error('Oversized response');
  return JSON.parse(text);
}
function esmState_(site) {
  return JSON.parse(PropertiesService.getScriptProperties().getProperty('ESM_STATE_'+site.id) || '{}');
}
function esmSaveState_(site,state) {
  PropertiesService.getScriptProperties().setProperty('ESM_STATE_'+site.id,JSON.stringify(state));
}
function esmFile_(folder,name) {
  const files=folder.getFilesByName(name), file=files.hasNext()?files.next():null;
  if (files.hasNext()) throw new Error('Duplicate journal name');
  return file;
}
function esmPublicCss_(site,health) {
  const names=[...new Set((health.files || []).filter(f=>f.required).map(f=>f.name))].slice(0,6);
  const results=[];
  for (const name of names) {
    if (!/^custom-[a-z0-9-]+\.min\.css$/.test(name)) throw new Error('Invalid stylesheet name');
    try {
      const res=UrlFetchApp.fetch(site.cssBaseUrl+name,{method:'get',followRedirects:false,muteHttpExceptions:true,headers:{'Cache-Control':'no-cache'}});
      const headers=res.getAllHeaders();
      const type=Object.keys(headers).find(k=>k.toLowerCase()==='content-type');
      const ok=res.getResponseCode()===200 && /^text\/css\b/i.test(String(type?headers[type]:''));
      results.push({name:name,status:res.getResponseCode(),ok:ok});
    } catch(e) { results.push({name:name,status:null,ok:false}); }
  }
  return {checked:results.length,failed:results.filter(r=>!r.ok).length,results:results};
}
function esmValidate_(site,data) {
  if (data.schemaVersion!==1 || data.site!==site.site || !Array.isArray(data.events) || data.events.length>50
      || !Array.isArray(data.statusRows) || data.statusRows.length>24 || !data.cssHealth
      || !['healthy','attention','unavailable','awaiting_regeneration'].includes(data.cssHealth.status)
      || Math.abs(Date.now()-Date.parse(data.generatedUtc))>300000 || !Number.isFinite(Date.parse(data.generatedUtc))
      || !Number.isFinite(Date.parse(data.cssHealth.checkedUtc)) || Math.abs(Date.now()-Date.parse(data.cssHealth.checkedUtc))>300000
      || !Array.isArray(data.cssHealth.files) || data.cssHealth.files.length>128) throw new Error('Invalid or stale snapshot');
  for (const r of data.statusRows) {
    if (!r || typeof r.id!=='string' || typeof r.label!=='string' || typeof r.status!=='string' || typeof r.detail!=='string') throw new Error('Invalid status rows');
  }
  for (const r of data.events) {
    if (!r || !Number.isFinite(Date.parse(r.utc)) || !Array.isArray(r.trace) || !Array.isArray(r.hooks)) throw new Error('Invalid event');
  }
}
function esmStore_(site,data,http,state) {
  const now=new Date(), folder=DriveApp.getFolderById(site.folderId);
  const name='esm-'+site.id+'-'+Utilities.formatDate(now,'UTC','yyyy-MM-dd')+'.json';
  const file=esmFile_(folder,name);
  const journal=file?JSON.parse(file.getBlob().getDataAsString()):{schemaVersion:1,site:site.site,collections:[],events:{}};
  if (journal.site!==site.site || !Array.isArray(journal.collections) || !journal.events) throw new Error('Journal mismatch');
  const ids=data.events.map(r=>esmHash_(JSON.stringify(r)));
  const gapPossible=data.events.length===50 && !!state.lastEventId && !ids.includes(state.lastEventId);
  const previousIds=new Set(state.seenEventIds || []);
  data.events.forEach((event,i)=>{ if (!Object.prototype.hasOwnProperty.call(journal.events,ids[i])) journal.events[ids[i]]={event:event,firstSeenUtc:previousIds.has(ids[i])?null:now.toISOString()}; });
  journal.collections.push({receivedUtc:now.toISOString(),snapshot:Object.assign({},data,{events:undefined}),http:http,gapPossible:gapPossible});
  const content=JSON.stringify(journal);
  if (content.length>4000000 || journal.collections.length>100) throw new Error('Daily journal limit');
  if (file) { file.setSharing(DriveApp.Access.PRIVATE, DriveApp.Permission.VIEW); file.setContent(content); } else { folder.createFile(name,content,MimeType.PLAIN_TEXT).setSharing(DriveApp.Access.PRIVATE, DriveApp.Permission.VIEW); }
  state.seenEventIds=ids;
  state.lastCollection=now.toISOString();
  if (ids.length) state.lastEventId=ids[ids.length-1];
  return journal.collections[journal.collections.length-1];
}
function esmCollectAll() {
  const config=esmConfig_(), lock=LockService.getScriptLock();
  if (!lock.tryLock(1000)) return;
  try {
    for (const input of config.sites) {
      let state,site=input;
      try {
        site=esmPrepareSite_(input,config,true);
        state=esmState_(site);
        const data=esmRequest_(site,'GET','/stylesheet-monitor/v1/snapshot');
        esmValidate_(site,data);
        const http=esmPublicCss_(site,data.cssHealth);
        const collection=esmStore_(site,data,http,state);
        esmSaveState_(site,state); // A durable journal precedes the signed receipt.
        try {
          const ack=esmRequest_(site,'POST','/stylesheet-monitor/v1/receipt',{checkedUtc:new Date().toISOString(),http:{checked:http.checked,failed:http.failed}});
          collection.receiptAccepted=ack.ok===true;
        } catch(e) { collection.receiptAccepted=false; }
        state.lastReceiptAccepted=collection.receiptAccepted;
        try {
          const folder=DriveApp.getFolderById(site.folderId);
          const file=esmFile_(folder,'esm-'+site.id+'-'+Utilities.formatDate(new Date(collection.receivedUtc),'UTC','yyyy-MM-dd')+'.json');
          if (file) {
            const journal=JSON.parse(file.getBlob().getDataAsString());
            const saved=journal.collections.find(c=>c.receivedUtc===collection.receivedUtc);
            if (saved) { saved.receiptAccepted=collection.receiptAccepted; file.setContent(JSON.stringify(journal)); }
          }
        } catch(e) { console.warn('Receipt journal update failed for '+site.id); }
        // Receipt delivery is independent from source collection health.
        esmAlarm_(site,state,collection,false);
        esmSaveState_(site,state);
      } catch(e) {
        console.warn('Collection processing could not complete for '+site.id+'; inspect configuration, Drive access, endpoint and quotas.');
        // Do not relabel a mail failure as a collection failure.
        if (state && (!state.lastCollection || Date.now()-Date.parse(state.lastCollection)>300000)) {
          try { esmAlarm_(site,state,null,true); esmSaveState_(site,state); } catch(mailError) { console.warn('Alarm delivery failed for '+site.id); }
        }
      }
    }
  } finally { lock.releaseLock(); }
}
