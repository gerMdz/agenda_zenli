<script setup>
import brandImage from './image/index.png'
</script>

<template>
  <main class="cover" aria-labelledby="cover-title">
    <header class="brand">
      <h1 id="cover-title">
        <img :src="brandImage" alt="Zenli">
      </h1>
      <p>Centro de masajes</p>
    </header>

    <section class="details" aria-label="Información del centro">
      <address>
        <a href="https://www.google.com/maps/search/?api=1&query=Espejo%20167%2C%20Ciudad%20de%20Mendoza">
          Espejo 167, Ciudad de Mendoza
        </a>
      </address>
      <p>Lun, mié y vie · 10:00 a 20:00<br>Mar, jue y sáb · 11:00 a 19:00</p>
      <a class="contact" href="tel:+542612186201">Llamar al 261 218 6201</a>
    </section>
  </main>
</template>

<style>
:root {
  color: #f9f3e7;
  background: #17382e;
  font-family: system-ui, sans-serif;
}

body {
  margin: 0;
}

.cover {
  box-sizing: border-box;
  display: grid;
  align-content: center;
  justify-items: center;
  gap: 2rem;
  min-height: 100svh;
  padding: 3rem 1.25rem;
  text-align: center;
}

.brand h1 {
  margin: 0;
  font: 400 clamp(4.75rem, 16vw, 8rem) / 1 Georgia, serif;
}

.brand img {
  display: block;
  width: min(100%, 22rem);
  max-height: 46vh;
  object-fit: contain;
  margin-inline: auto;
  border-radius: 1rem;
}

.brand p {
  color: #e9c887;
  font-size: 0.85rem;
  letter-spacing: 0.15em;
  text-transform: uppercase;
}

.details {
  border-top: 1px solid #e9c88780;
  padding: 1.5rem 0.5rem 0;
  line-height: 1.65;
}

address {
  font-style: normal;
}

address a {
  color: inherit;
  text-underline-offset: 0.2em;
}

.contact {
  display: inline-block;
  border-radius: 2rem;
  padding: 0.65rem 1.2rem;
  background: #e9c887;
  color: #17382e;
  font-weight: 650;
  text-decoration: none;
}

a:focus-visible {
  outline: 2px solid #fff;
  outline-offset: 4px;
}
</style>
