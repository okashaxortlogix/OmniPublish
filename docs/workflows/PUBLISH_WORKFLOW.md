# End-to-End Publish Workflow

```text
User
 |
 | upload
 v
Object Storage
 |
 | processing
 v
Media Asset READY
 |
 | compose
 v
Content Item
 |
 | select destinations
 v
Preflight Validator
 |
 +-- invalid --> UI corrections
 |
 +-- valid
 v
Publish Batch
 |
 +--> Destination Job A
 +--> Destination Job B
 +--> Destination Job C
 |
 v
Queue
 |
 v
Platform Worker
 |
 +--> adapter.validate()
 +--> adapter.upload/create()
 +--> provider processing
 +--> poll/webhook
 |
 v
Published / Failed / Auth Required
 |
 v
Dashboard + Audit Log
```

## Scheduling

```text
Scheduled Content
      |
      v
Scheduler
      |
      v
Create Publish Batch
      |
      v
Queue destination jobs
```
