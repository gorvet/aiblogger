=== AIBlogger ===
Contributors: Juank de Gorvet
Tags: gemini, ai, blog, seo
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Genera artículos, imágenes y metadatos SEO con Gemini desde WordPress.

== Description ==

AIBlogger convierte un tema en un artículo de WordPress. Puedes guardar el resultado como borrador para revisarlo, enviarlo a revisión, dejarlo privado o publicarlo directamente.

* Personaliza el idioma, la extensión, el contexto del negocio y las fuentes que quieres usar.
* Genera una imagen destacada e imágenes dentro del artículo.
* Guarda el título SEO, la metadescripción y la palabra clave en los campos de Yoast SEO y Rank Math.
* Reescribe texto y genera imágenes desde el editor.
* Consulta el consumo de tokens y configura límites para todo el sitio.

== Installation ==

1. Coloca la carpeta del plugin en wp-content/plugins/aiblogger/ y actívalo desde Plugins.
2. Abre AIBlogger → Configuración. Introduce tu clave de la API de Gemini, elige los modelos de texto e imagen y guarda los cambios.
3. Abre AIBlogger → Generar. Indica el tema y selecciona Borrador si quieres revisar el artículo antes de publicarlo.

== Frequently Asked Questions ==

= ¿Cómo activo las actualizaciones automáticas? =

En la pantalla Plugins, busca AIBlogger y habilita las actualizaciones automáticas en su fila. WordPress mostrará las versiones publicadas en el repositorio de Gorvet.

== Changelog ==

= 1.0.6 =
* Las acciones del editor se cargan por separado del flujo de generación de artículos.
* WordPress gestiona las actualizaciones automáticas desde el repositorio de Gorvet.
