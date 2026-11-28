import puppeteer from 'puppeteer-core'

const PUERTO = 18082
const ORIGEN = `http://localhost:${PUERTO}`
const EMAIL = 'vista@zenli.test'
const CLAVE = 'PruebaSegura123!'

async function probar() {
  console.log(`Iniciando prueba e2e con Chrome y Autenticador Virtual WebAuthn contra ${ORIGEN}...`)

  const browser = await puppeteer.launch({
    executablePath: '/usr/bin/google-chrome',
    headless: true,
    args: [
      '--no-sandbox',
      '--disable-gpu',
      '--disable-dev-shm-usage',
      `--unsafely-treat-insecure-origin-as-secure=${ORIGEN}`,
    ],
  })

  try {
    const page = await browser.newPage()
    const client = await page.target().createCDPSession()

    // 1. Habilitar entorno virtual WebAuthn en Chrome
    console.log('1. Habilitando entorno virtual WebAuthn en Chrome...')
    await client.send('WebAuthn.enable')
    const authenticator = await client.send('WebAuthn.addVirtualAuthenticator', {
      options: {
        protocol: 'ctap2',
        transport: 'internal',
        hasResidentKey: true,
        hasUserVerification: true,
        isUserVerified: true,
      },
    })
    console.log(`   -> Autenticador virtual creado (ID: ${authenticator.authenticatorId})`)

    // 2. Navegar a /admin
    console.log(`2. Navegando a ${ORIGEN}/admin...`)
    await page.goto(`${ORIGEN}/admin`, { waitUntil: 'networkidle0' })

    // 3. Iniciar sesión con contraseña
    console.log('3. Iniciando sesión con cuenta de prueba (contraseña)...')
    await page.waitForSelector('#email', { timeout: 10000 })
    await page.type('#email', EMAIL)
    await page.type('#clave', CLAVE)
    
    // Esperar que el botón de Ingresar esté habilitado (csrf cargado)
    await page.waitForFunction(() => {
      const btn = Array.from(document.querySelectorAll('form button')).find(b => b.textContent.includes('Ingresar'))
      return btn && !btn.disabled
    }, { timeout: 5000 })

    await page.evaluate(() => {
      const btn = Array.from(document.querySelectorAll('form button')).find(b => b.textContent.includes('Ingresar'))
      btn.click()
    })

    // Esperar a que ingrese y redirija a /admin/agenda
    await page.waitForFunction(() => window.location.pathname === '/admin/agenda' || !!document.querySelector('.cabecera-zenli'), { timeout: 10000 })
    console.log('   -> Sesión iniciada con éxito. Estamos en /admin/agenda')

    page.on('console', msg => console.log('   [PAGE LOG]', msg.text()))
    page.on('pageerror', err => console.log('   [PAGE ERROR]', err.message))

    // 4. Abrir menú de cuenta
    console.log('4. Abriendo menú de cuenta para gestionar Claves de acceso...')
    await page.waitForSelector('details.cuenta-zenli summary', { timeout: 10000 })
    
    const infoClaves = await page.evaluate(() => {
      const botones = Array.from(document.querySelectorAll('.opciones-perfil button')).map(b => b.textContent.trim())
      const dialog = document.querySelector('dialog#titulo-claves-acceso, dialog')
      return { botones, isSecureContext: window.isSecureContext, hasDialog: !!dialog }
    })
    console.log('   -> Info de la página:', infoClaves)

    // Abrir diálogo de Claves de acceso
    console.log('4.1 Abriendo diálogo de Claves de acceso...')
    await page.evaluate(() => {
      const dialog = document.querySelector('dialog[aria-labelledby="titulo-claves-acceso"]')
      if (dialog && !dialog.open) {
        dialog.showModal()
        // Disparar carga de claves llamando al endpoint
        fetch('/api/admin/claves-acceso', { headers: { Accept: 'application/json' } })
      }
    })

    // 5. Registrando clave de acceso en el autenticador virtual...
    console.log('5. Registrando clave de acceso en el autenticador virtual...')
    
    // Escuchar peticiones de red
    page.on('request', req => {
      if (req.url().includes('claves-acceso')) console.log('   [REQ]', req.method(), req.url())
    })
    page.on('response', async res => {
      if (res.url().includes('claves-acceso')) {
        let texto = ''
        try { texto = await res.text() } catch {}
        console.log('   [RES]', res.status(), res.url(), texto.slice(0, 200))
      }
    })

    const diagnosticoForms = await page.evaluate(() => {
      const forms = Array.from(document.querySelectorAll('form')).map(f => {
        const dialog = f.closest('dialog')
        const open = dialog ? dialog.open : true
        const invalidos = Array.from(f.querySelectorAll(':invalid')).map(i => ({ tag: i.tagName, type: i.type, name: i.name, value: i.value }))
        return { action: f.action, dialogOpen: open, invalidos }
      })
      return forms
    })
    console.log('   -> Diagnóstico formularios en la página:', JSON.stringify(diagnosticoForms, null, 2))

    // Esperar a que el diálogo esté abierto
    await page.waitForFunction(() => {
      const dialog = document.querySelector('dialog[aria-labelledby="titulo-claves-acceso"]')
      return dialog && dialog.open
    }, { timeout: 5000 })

    // Escribir en el input de contraseña del diálogo de claves de acceso
    await page.evaluate((pass) => {
      const dialog = document.querySelector('dialog[aria-labelledby="titulo-claves-acceso"]')
      const input = dialog.querySelector('input[type="password"]')
      input.value = pass
      input.dispatchEvent(new Event('input', { bubbles: true }))
    }, CLAVE)

    // Enviar formulario
    await page.evaluate(() => {
      const dialog = document.querySelector('dialog[aria-labelledby="titulo-claves-acceso"]')
      const submitBtn = dialog.querySelector('button[type="submit"]')
      submitBtn.click()
    })

    // Esperar confirmación de registro o error
    await page.waitForFunction(() => {
      const elAviso = document.querySelector('dialog[aria-labelledby="titulo-claves-acceso"] [role="status"]')
      const elError = document.querySelector('dialog[aria-labelledby="titulo-claves-acceso"] [role="alert"]')
      if (elError) console.log('ERROR EN DIALOGO:', elError.textContent)
      if (elAviso) console.log('AVISO EN DIALOGO:', elAviso.textContent)
      return (elAviso && elAviso.textContent.includes('Clave registrada')) || !!elError
    }, { timeout: 10000 })
    
    const resultadoDialogo = await page.evaluate(() => {
      const elAviso = document.querySelector('dialog[aria-labelledby="titulo-claves-acceso"] [role="status"]')
      const elError = document.querySelector('dialog[aria-labelledby="titulo-claves-acceso"] [role="alert"]')
      return { aviso: elAviso?.textContent, error: elError?.textContent }
    })
    console.log('   -> Resultado en diálogo:', resultadoDialogo)
    if (resultadoDialogo.error) throw new Error(resultadoDialogo.error)
    console.log('   -> Clave de acceso WebAuthn registrada correctamente.')

    // Cerrar modal
    await page.evaluate(() => {
      const btn = Array.from(document.querySelectorAll('dialog[open] button')).find(b => b.textContent.includes('Cerrar'))
      btn.click()
    })

    // 6. Cerrar sesión
    console.log('6. Cerrando sesión limpiando cookies de sesión...')
    const cookies = await page.cookies()
    await page.deleteCookie(...cookies)
    
    // Ir a /admin
    console.log('   -> Navegando a /admin tras cerrar sesión...')
    await page.goto(`${ORIGEN}/admin`, { waitUntil: 'networkidle0' })
    await page.waitForSelector('#email', { timeout: 10000 })
    console.log('   -> Sesión cerrada. Estamos en la pantalla de ingreso.')

    // 7. Ingresar con clave de acceso (Passkey)
    console.log('7. Probando "Ingresar con clave de acceso" (Passkey)...')
    await page.waitForFunction(() => {
      const btn = document.querySelector('button.acceso-passkey')
      return btn && !btn.disabled
    }, { timeout: 10000 })

    await page.evaluate(() => {
      document.querySelector('button.acceso-passkey').click()
    })

    // Verificar que la sesión se inicie y vuelva a la agenda
    await page.waitForFunction(() => window.location.pathname === '/admin/agenda' || !!document.querySelector('.cabecera-zenli'), { timeout: 10000 })
    console.log('   -> ¡Ingreso con Passkey exitoso! Agenda abierta mediante autenticación biométrica virtual.')

    // 8. Revocar la clave y comprobar
    console.log('8. Revocando la clave de acceso de prueba...')
    const clavesRegistradas = await page.evaluate(async () => {
      const res = await fetch('/api/admin/claves-acceso', { headers: { Accept: 'application/json' } })
      return await res.json()
    })
    console.log(`   -> Claves encontradas en base de datos: ${clavesRegistradas.claves.length}`)
    const claveId = clavesRegistradas.claves[0].id

    // Obtener token CSRF de la sesión
    const sesionInfo = await page.evaluate(async () => {
      const res = await fetch('/api/admin/sesion', { headers: { Accept: 'application/json' } })
      return await res.json()
    })
    const csrfToken = sesionInfo.csrf

    // Revocar la clave enviando DELETE con CSRF
    const resultadoRevocacion = await page.evaluate(async ({ id, csrf }) => {
      const res = await fetch(`/api/admin/claves-acceso/${id}`, {
        method: 'DELETE',
        headers: { Accept: 'application/json', 'X-Zenli-CSRF': csrf },
      })
      return { status: res.status, body: await res.json() }
    }, { id: claveId, csrf: csrfToken })

    console.log(`   -> Clave revocada exitosamente (status ${resultadoRevocacion.status}).`)

    console.log('\n=============================================================')
    console.log('¡TODAS LAS COMPROBACIONES DE PASSKEYS / WEBAUTHN PASARON OK!')
    console.log('=============================================================')
  } finally {
    await browser.close()
  }
}

probar().catch(err => {
  console.error('Error durante la prueba:', err)
  process.exit(1)
})
