# AiBlogger para WordPress

Genera un artículo con Gemini a partir de un tema y guárdalo como borrador en WordPress. Puedes darle contexto sobre tu negocio, añadir instrucciones y fuentes, y generar una imagen destacada o ilustraciones para el contenido.

## Qué puedes hacer

| Desde WordPress | Qué puedes configurar |
| --- | --- |
| **AIBlogger → Generar** | Define el tema, el idioma, el rango de palabras, el tipo de contenido y su estado. Puedes dejar el artículo en borrador, pendiente de revisión o privado, o publicarlo directamente. |
| **AIBlogger → Configuración** | Elige los modelos de texto e imagen de Gemini. Guarda el contexto del negocio, configura el enlazado interno y externo, y ajusta el formato de las imágenes. |
| **Editor de WordPress** | Usa el panel de AiBlogger para reescribir texto o generar una imagen a partir de una descripción. |
| **AIBlogger → Métricas** | Consulta el consumo de tokens por operación y por artículo. Los límites diarios y mensuales pueden emitir avisos o bloquear nuevas generaciones. |

AiBlogger también puede guardar el título SEO, la metadescripción y la palabra clave en los campos que usan Yoast SEO y Rank Math.

## Requisitos

- WordPress 6.5 o superior y PHP 8.1 o superior.
- Una clave de la API de Gemini con acceso a los modelos que quieras usar. [Guía de Google para crear una clave](https://aistudio.google.com/docs/api-key)
- Permisos para instalar el plugin y guardar su configuración en WordPress.

## Instalación y primer artículo

1. Coloca el plugin en `wp-content/plugins/aiblogger/`. Si descargaste el código desde GitHub, extrae el ZIP y renombra la carpeta extraída a `aiblogger`. El archivo de entrada debe quedar en `wp-content/plugins/aiblogger/aiblogger.php`.
2. En **Plugins**, activa **AIBlogger**.
3. En **AIBlogger → Configuración**, introduce tu clave de Gemini, selecciona los modelos y guarda los cambios. En **Contexto del negocio/sitio** puedes indicar a quién va dirigido el contenido y qué tono debe seguir.
4. En **AIBlogger → Generar**, escribe el tema y selecciona **Borrador** para revisar el resultado antes de publicarlo. Si quieres que Gemini use fuentes específicas, pega sus enlaces en **Instrucciones extra**.

Las solicitudes de generación usan la cuenta de Gemini asociada a tu clave. El consumo que muestra AiBlogger sirve para seguir el uso desde WordPress.

## Actualizaciones

WordPress consulta el manifiesto del repositorio de Gorvet. Cuando haya una nueva versión publicada, aparecerá en **Plugins**. Desde esa misma pantalla puedes habilitar las actualizaciones automáticas en la fila de **AIBlogger**. [Manifiesto de versiones](https://repo.gorvet.com/updates/aiblogger/info.json)

## Licencia

GPL v2 o posterior. [Texto de la licencia](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)
