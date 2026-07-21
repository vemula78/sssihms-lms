# Course import JSON format

`import-courses.php` consumes a UTF-8 JSON array. The DOCX parser that produces
this file is external to this repository; its output must conform to this shape:

```json
[
  {
    "title": "Course title",
    "description": "Optional course description",
    "track": "clinical | allied | chaplaincy",
    "modules": [
      {
        "title": "Module title",
        "lessons": [
          {
            "title": "Lesson title",
            "content": "<p>Optional lesson HTML</p>",
            "video": "https://drive.google.com/file/d/FILE_ID/view"
          }
        ]
      }
    ]
  }
]
```

`title` and non-empty `modules`/`lessons` arrays are required. `description`,
`track`, `content`, and `video` are optional strings; `description` defaults to
a generic note, `track` defaults to `allied`, and `track` (when given) must be
one of `clinical`, `allied`, `chaplaincy`. The importer validates the entire
file before writing, creates each course as a draft in a transaction, and
publishes it only after all modules and lessons succeed. Existing course
titles are skipped.

Run it from the `dev` directory:

```bash
wp eval-file import-courses.php /absolute/path/to/courses.json --url=site.example
```
