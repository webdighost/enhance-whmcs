import json, ssl, threading, sys
from http.server import HTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse, parse_qs
MASTER='11111111-2222-4333-8444-555555555555'
CUST='aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'
MEMBER='99999999-8888-4777-8666-555555555555'
GOOD='tok_GOOD&abc'   # contains & to test WHMCS HTML-encoding
LOG=[]
CUST2='cccccccc-bbbb-4ccc-8ddd-eeeeeeeeeeee'
STATE={'members':{'dddddddd-0000-4000-8000-000000000099':{'id':'dddddddd-0000-4000-8000-000000000099','loginId':'eeeeeeee-1111-4222-8333-444444444444','roles':['SuperAdmin']}}, 'owner':None, 'n':0}
class H(BaseHTTPRequestHandler):
    mode='api'
    def log_message(self,*a): pass
    def send(self, code, body, ctype='application/json'):
        data = body if isinstance(body, bytes) else json.dumps(body).encode()
        self.send_response(code); self.send_header('Content-Type', ctype); self.send_header('Content-Length', str(len(data))); self.end_headers(); self.wfile.write(data)
    def handle_any(self):
        n=int(self.headers.get('Content-Length') or 0); body=self.rfile.read(n) if n else b''
        LOG.append((self.command, self.path, self.headers.get('Authorization'), self.headers.get('Content-Length'), body.decode()))
        if self.mode=='html': return self.send(200, b'<html>site</html>', 'text/html')
        if self.mode=='redirect':
            self.send_response(301); self.send_header('Location','https://cp.real.test/api/orgs'); self.send_header('Content-Length','0'); self.end_headers(); return
        if self.path=='/api/version':
            return self.send(200, '12.26.3') if self.headers.get('Authorization') is None else self.send(400, {'code':'unexpected_auth'})
        if self.path.startswith('/api/login/password-recovery'):
            return self.send(200 if self.command=='PUT' else 400, b'', 'text/plain')
        if self.headers.get('Authorization') != 'Bearer '+GOOD:
            return self.send(401, {'code':'unauthorized','detail':'Invalid token'})
        u=urlparse(self.path); p=u.path; q=parse_qs(u.query)
        if p=='/api/__log': return self.send(200, LOG)
        if p=='/api/__reset':
            STATE['members'].clear(); STATE['owner']=None; return self.send(200, {'mark': len(LOG)})
        if p.startswith(f'/api/orgs/{CUST2}'):
            j=json.loads(body or b'{}')
            if p==f'/api/orgs/{CUST2}': return self.send(200, {'id':CUST2,'parentId':MASTER,'name':'C2','ownerId':STATE['owner']} if STATE['owner'] else {'id':CUST2,'parentId':MASTER,'name':'C2'})
            if p==f'/api/orgs/{CUST2}/members' and self.command=='GET':
                return self.send(200, {'items':list(STATE['members'].values()),'total':len(STATE['members'])})
            if p==f'/api/orgs/{CUST2}/members' and self.command=='POST':
                STATE['n']+=1; mid='dddddddd-0000-4000-8000-%012d'%STATE['n']
                STATE['members'][mid]={'id':mid,'loginId':j['loginId'],'roles':j['roles']}
                if 'Owner' in j['roles']: STATE['owner']=mid
                return self.send(201, {'id':mid})
            if p==f'/api/orgs/{CUST2}/owner': return self.send(403, {'code':'unauthorized','detail':'Forbidden'})
            mid=p.split('/')[-1]
            if self.command=='GET': return self.send(200, STATE['members'][mid])
            if self.command=='PUT':
                if 'Owner' in j.get('roles',[]): return self.send(400, {'code':'http_400','detail':'Json deserialize error: unknown variant `Owner`'})
                STATE['members'][mid].update(j); return self.send(200, b'', 'text/plain')
            if self.command=='DELETE':
                STATE['members'].pop(mid, None); return self.send(204, b'', 'text/plain')
        if p==f'/api/orgs/{MASTER}': return self.send(200, {'id':MASTER,'name':'Master'})
        WEB='abababab-0000-4000-8000-000000000001'; APP='cdcdcdcd-0000-4000-8000-000000000001'; W=f'/api/orgs/{CUST}/websites/{WEB}'
        site={'id':WEB,'domain':{'domain':'acme.pt'},'subscriptionId':5,'status':'active','kind':'normal','phpVersion':'php83'}
        if p==f'/api/orgs/{MASTER}/customers/{CUST}/subscriptions': return self.send(200, {'items':[{'id':5,'planId':1,'planName':'Basic','subscriberId':CUST,'vendorId':MASTER,'status':'active','friendlyName':'WHMCS #50'},{'id':6,'planId':2,'planName':'Pro','subscriberId':CUST,'vendorId':MASTER,'status':'active','friendlyName':''},{'id':9,'planId':2,'planName':'Old','subscriberId':CUST,'vendorId':MASTER,'status':'deleted','friendlyName':''}],'total':3})
        if p==f'/api/orgs/{CUST}/subscriptions/6': return self.send(200, {'id':6,'planId':2,'planName':'Pro','subscriberId':CUST,'vendorId':MASTER,'status':'active','resources':[]})
        if p==f'/api/orgs/{CUST}/subscriptions/5' and self.command=='PATCH': return self.send(500, {'code':'internal','detail':'Server error'})
        if p==f'/api/orgs/{CUST}/subscriptions/5': return self.send(200, {'id':5,'planId':1,'planName':'Basic','subscriberId':CUST,'vendorId':MASTER,'status':'active','resources':[{'name':'diskspace','usage':1073741824,'total':10737418240}]})
        if p==f'/api/orgs/{CUST}/websites' and self.command=='GET':
            # acme.pt plus 11 more websites, to test the client area pagination
            extra=[{'id':'abababab-0000-4000-8000-%012d'%(100+i),'domain':{'domain':'site%02d.pt'%i},'subscriptionId':5,'status':'active','kind':'normal','phpVersion':'php82'} for i in range(1,12)]
            return self.send(200, {'items':[site]+extra,'total':12})
        if p==W: return self.send(200, site)
        if p==W+'/domains': return self.send(200, {'items':[{'domain':'acme.pt','mappingKind':'primary','cert':{'cn':'acme.pt','issuer':"Let's Encrypt",'issued':'2026-10-01T00:00:00Z','expires':'2026-11-08T00:00:00Z','sans':[],'forceHttps':True}}]})
        if p==W+'/emails': return self.send(200, {'items':[{'address':'info@acme.pt','hasMailbox':True,'ssoAvailable':True,'status':'active','aliases':[]},{'address':'fwd@acme.pt','hasMailbox':False,'status':'active','aliases':[]}],'total':2})
        if p==W+'/emails/info%40acme.pt/sso' or p==W+'/emails/info@acme.pt/sso':
            self.send_response(302); self.send_header('Location','https://cp.test/roundcube/?_sso=abc'); self.send_header('Content-Length','0'); self.end_headers(); return
        if p==W+'/mysql-dbs': return self.send(200, {'items':[{'name':'acme_wp','size':1,'createdAt':'x','userCount':1}]})
        if p==W+'/mysql-dbs/acme_wp/sso': return self.send(200, 'https://cp.test/phpmyadmin/?sso=1')
        if p==W+'/apps': return self.send(200, {'items':[{'id':APP,'app':'wordpress','version':'6.6','path':'public_html'}]})
        if p==W+f'/apps/{APP}/wordpress/users': return self.send(200, {'items':[{'id':1,'login':'editor','email':'e@acme.pt','role':'editor'},{'id':2,'login':'admin','email':'a@acme.pt','role':'administrator'}]})
        if p==W+f'/apps/{APP}/wordpress/users/2/sso': return self.send(200, 'https://acme.pt/wp-admin/?sso=2')
        if p==W+'/backups': return self.send(200, {'items':[{'id':7,'startedAt':'2026-10-27T03:00:00Z','finishedAt':'2026-10-27T03:10:00Z','size':52428800,'homeDirStatus':'successful','kind':'automatic','storageKind':'enhance','snapshotDirName':'a'},{'id':8,'startedAt':'2026-10-28T03:00:00Z','finishedAt':'2026-10-28T03:05:00Z','size':1048576,'homeDirStatus':'failed','kind':'automatic','storageKind':'enhance','snapshotDirName':'b'},{'id':9,'startedAt':'2026-10-29T03:00:00Z','finishedAt':'2026-10-29T03:00:05Z','size':0,'homeDirStatus':'failed','kind':'automatic','storageKind':'enhance','snapshotDirName':'c'}]})
        if p==W+'/backups/7' and self.command=='PUT': STATE['restored']=json.loads(body or b'{}'); return self.send(200, b'', 'text/plain')
        if p=='/api/servers/groups': return self.send(200, {'items':[{'id':'efefefef-0000-4000-8000-000000000001','name':'Lisboa','serverCount':3,'createdAt':'x'}]})
        if p=='/api/__state': return self.send(200, {k:v for k,v in STATE.items() if k!='members'})
        if p==f'/api/orgs/{CUST}': return self.send(200, {'id':CUST,'parentId':MASTER,'ownerId':MEMBER,'name':'Cust','ownerEmail':'owner@acme.pt'})
        if p==f'/api/orgs/{MASTER}/plans':
            off=int(q.get('offset',['0'])[0]); items=[{'id':i,'name':f'Plan {i}'} for i in range(1,6)]
            return self.send(200, {'items':items[off:off+2],'total':5})
        if p==f'/api/orgs/{MASTER}/customers' and self.command=='POST':
            STATE['members'].clear(); STATE['owner']=None
            return self.send(201, {'id':CUST2})
        if p==f'/api/orgs/{MASTER}/customers':
            custs=[{'id':CUST,'name':'Acme Lda','ownerEmail':'acme@x.pt','parentId':MASTER,'status':'active'},{'id':CUST2,'name':'Beta','ownerEmail':'b@x.pt','parentId':MASTER,'status':'active'}]
            term=q.get('search',[''])[0].lower()
            items=[c for c in custs if term in c['name'].lower()] if 'search' in q else []
            return self.send(200, {'items':items,'total':len(items)})
        if p=='/api/logins' and self.command=='GET':
            mine=[{'id':'11111111-aaaa-4aaa-8aaa-000000000001','email':'dup@x.pt'}]
            other=[{'id':'22222222-aaaa-4aaa-8aaa-000000000002','email':'dup@x.pt'},{'id':'33333333-aaaa-4aaa-8aaa-000000000003','email':'other@x.pt'}]
            items=mine if q.get('realmId',[''])[0]==MASTER else mine+other
            return self.send(200, {'items':items,'total':len(items)})
        if p=='/api/logins' and self.command=='POST':
            if json.loads(body)['email']=='other@x.pt': return self.send(409, {'code':'already_exists','detail':'Login exists'})
            return self.send(201, {'id':'44444444-aaaa-4aaa-8aaa-000000000004'})
        if p=='/api/login/password-recovery': return self.send(200, b'', 'text/plain')
        if p==f'/api/orgs/{CUST}/members/{MEMBER}': return self.send(200, {'id':MEMBER,'roles':['Owner']})
        if p==f'/api/orgs/{CUST}/members/{MEMBER}/sso': return self.send(200, 'https://cp.test/sso?token=xyz')
        if p.endswith('/calculate-resource-usage'): return self.send(204, b'', 'text/plain')
        if p.endswith('/bandwidth'): return self.send(200, 123456)
        return self.send(404, {'code':'not_found','detail':'Not found'})
    do_GET=do_POST=do_PUT=do_PATCH=do_DELETE=handle_any
def serve(port, mode):
    cls=type('H'+mode,(H,),{'mode':mode})
    s=HTTPServer(('127.0.0.1',port),cls)
    ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); ctx.load_cert_chain('cert.pem','key.pem')
    s.socket=ctx.wrap_socket(s.socket, server_side=True); s.serve_forever()
for port,mode in [(18443,'api'),(18444,'html'),(18445,'redirect')]:
    threading.Thread(target=serve,args=(port,mode),daemon=True).start()
threading.Event().wait()
