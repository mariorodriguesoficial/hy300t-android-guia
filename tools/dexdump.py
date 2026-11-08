"""Desmontador mínimo de classes.dex (strings, chamadas e desvios) - sem dependências.
Uso: python3 dexdump.py arquivo.apk [filtro_de_pacote]
Ex.: python3 dexdump.py JmLauncher.apk jmlauncher
"""
import struct, sys, zipfile

apk = sys.argv[1]
FILTRO = sys.argv[2] if len(sys.argv) > 2 else ''
d = zipfile.ZipFile(apk).read('classes.dex')

def u4(o): return struct.unpack_from('<I', d, o)[0]
def u2(o): return struct.unpack_from('<H', d, o)[0]

def uleb(o):
    r = 0; s = 0
    while True:
        b = d[o]; o += 1
        r |= (b & 0x7f) << s; s += 7
        if b < 0x80: return r, o

str_sz, str_off = u4(0x38), u4(0x3c)
type_sz, type_off = u4(0x40), u4(0x44)
proto_sz, proto_off = u4(0x48), u4(0x4c)
field_sz, field_off = u4(0x50), u4(0x54)
meth_sz, meth_off = u4(0x58), u4(0x5c)
cls_sz, cls_off = u4(0x60), u4(0x64)

def string(i):
    o = u4(str_off + 4*i)
    _, o = uleb(o)
    e = d.index(b'\0', o)
    return d[o:e].decode('utf-8', 'replace')

def typ(i): return string(u4(type_off + 4*i))
def field(i):
    o = field_off + 8*i
    return f"{typ(u2(o))}->{string(u4(o+4))}"
def meth(i):
    o = meth_off + 8*i
    return f"{typ(u2(o))}->{string(u4(o+4))}"

# formatos das instruções: opcode -> (tamanho_em_unidades_de_16_bits, tipo)
# tipos: 'str' (const-string 21c), 'strj' (31c), 'type' (tipo 21c), 'field' (campo), 'meth' (método), 'other' (outros)
size = {}
def setr(a, b, sz, kind='other'):
    for op in range(a, b+1): size[op] = (sz, kind)
setr(0x00,0x00,1); setr(0x01,0x01,1); setr(0x02,0x02,2); setr(0x03,0x03,3)
setr(0x04,0x04,1); setr(0x05,0x05,2); setr(0x06,0x06,3); setr(0x07,0x07,1)
setr(0x08,0x08,2); setr(0x09,0x09,3); setr(0x0a,0x0d,1); setr(0x0e,0x11,1)
setr(0x12,0x12,1); setr(0x13,0x13,2); setr(0x14,0x14,3); setr(0x15,0x15,2)
setr(0x16,0x16,2); setr(0x17,0x17,3); setr(0x18,0x18,5); setr(0x19,0x19,2)
setr(0x1a,0x1a,2,'str'); setr(0x1b,0x1b,3,'strj'); setr(0x1c,0x1c,2,'type')
setr(0x1d,0x1e,1); setr(0x1f,0x1f,2,'type'); setr(0x20,0x20,2,'type'); setr(0x21,0x21,1)
setr(0x22,0x22,2,'type'); setr(0x23,0x23,2,'type'); setr(0x24,0x24,3,'type'); setr(0x25,0x25,3,'type')
setr(0x26,0x26,3); setr(0x27,0x27,1); setr(0x28,0x28,1,'goto'); setr(0x29,0x29,2,'goto'); setr(0x2a,0x2a,3,'goto')
setr(0x2b,0x2c,3); setr(0x2d,0x31,2); setr(0x32,0x37,2,'if'); setr(0x38,0x3d,2,'ifz')
setr(0x3e,0x43,1); setr(0x44,0x51,2); setr(0x52,0x5f,2,'field'); setr(0x60,0x6d,2,'field')
setr(0x6e,0x72,3,'meth'); setr(0x73,0x73,1); setr(0x74,0x78,3,'meth'); setr(0x79,0x7a,1)
setr(0x7b,0x8f,1); setr(0x90,0xaf,2); setr(0xb0,0xcf,1); setr(0xd0,0xd7,2); setr(0xd8,0xe2,2)
setr(0xe3,0xf9,1); setr(0xfa,0xfa,4,'meth'); setr(0xfb,0xfb,4,'meth'); setr(0xfc,0xfd,3); setr(0xfe,0xff,2)
names = {0x6e:'invoke-virtual',0x6f:'invoke-super',0x70:'invoke-direct',0x71:'invoke-static',0x72:'invoke-interface',
         0x74:'invoke-virtual/range',0x75:'invoke-super/range',0x76:'invoke-direct/range',0x77:'invoke-static/range',0x78:'invoke-interface/range',
         0x1a:'const-string',0x1b:'const-string/jumbo',0x22:'new-instance',0x1f:'check-cast',0x20:'instance-of',
         0x32:'if-eq',0x33:'if-ne',0x34:'if-lt',0x35:'if-ge',0x36:'if-gt',0x37:'if-le',
         0x38:'if-eqz',0x39:'if-nez',0x3a:'if-ltz',0x3b:'if-gez',0x3c:'if-gtz',0x3d:'if-lez',
         0x28:'goto',0x29:'goto/16',0x2a:'goto/32',0x0f:'return',0x0e:'return-void',0x11:'return-object',
         0x12:'const/4',0x13:'const/16',0x14:'const'}

def disasm(code_off):
    regs, ins, outs, tries = u2(code_off), u2(code_off+2), u2(code_off+4), u2(code_off+6)
    n = u4(code_off+12)
    base = code_off + 16
    pc = 0
    while pc < n:
        o = base + 2*pc
        op = d[o]
        if op == 0x00 and d[o+1] != 0:  # bloco de dados (switch/array)
            ident = u2(o)
            if ident == 0x0100: pc += 4 + u2(o+2)*2
            elif ident == 0x0200: pc += 2 + u2(o+2)*4
            elif ident == 0x0300:
                w = u2(o+2); cnt = u4(o+4); pc += (8 + w*cnt + 1)//2
            else: pc += 1
            continue
        sz, kind = size.get(op, (1,'other'))
        nm = names.get(op, f'op_{op:02x}')
        extra = ''
        va = d[o+1]
        if kind == 'str': extra = repr(string(u2(o+2)))
        elif kind == 'strj': extra = repr(string(u4(o+2)))
        elif kind == 'type': extra = typ(u2(o+2))
        elif kind == 'field': extra = field(u2(o+2)); nm = ('iget/iput' if op < 0x60 else 'sget/sput') + f'_{op:02x}'
        elif kind == 'meth': extra = meth(u2(o+2))
        elif kind in ('if','ifz'):
            off = struct.unpack_from('<h', d, o+2)[0]; extra = f'-> {pc+off}'
        elif kind == 'goto':
            if op == 0x28: off = struct.unpack('<b', bytes([va]))[0]
            elif op == 0x29: off = struct.unpack_from('<h', d, o+2)[0]
            else: off = struct.unpack_from('<i', d, o+2)[0]
            extra = f'-> {pc+off}'
        elif op == 0x12:
            v = va >> 4; v = v-16 if v > 7 else v; extra = f'v{va&0xf}={v}'
        if kind != 'other' or op in names:
            print(f'    {pc:4d}: {nm} {extra}')
        pc += sz

for c in range(cls_sz):
    co = cls_off + 32*c
    cname = typ(u4(co))
    if FILTRO and FILTRO not in cname: continue
    data = u4(co+24)
    if not data: continue
    o = data
    sf, inf, dm, vm = [0]*4
    sf, o = uleb(o); inf, o = uleb(o); dm, o = uleb(o); vm, o = uleb(o)
    for _ in range(sf + inf):
        _, o = uleb(o); _, o = uleb(o)
    for cnt in (dm, vm):
        idx = 0
        for _ in range(cnt):
            diff, o = uleb(o); idx += diff
            _, o = uleb(o)
            code, o = uleb(o)
            print(f'== {meth(idx)}')
            if code: disasm(code)
